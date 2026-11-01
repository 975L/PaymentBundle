<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketRefundService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The checkout taken one step at a time, from the moment a basket is numbered to the moment it is delivered.
 *
 * What is pinned here is the rule the whole tunnel rests on: the site delivers on a payment a provider confirms,
 * never on the url the customer comes back with - that url is handed to them before they pay. Each step is asked
 * on its own, the two paths that reach it (the provider's webhook, the customer's return) sharing every gate.
 */
class BasketPaymentJourneyTest extends TestCase
{
    // What the items of these baskets are, as their provider says: posted by default, so the delivery is priced rather than skipped outright
    private int $providerFlags = Basket::CONTENT_FLAG_PHYSICAL;

    // What the shop's configuration says beyond its currency, read as unset (0) when a test does not set it
    /** @var array<string, mixed> */
    private array $config = [];

    private ?Envelope $dispatched = null;

    /** @var list<string> */
    private array $delivered = [];

    private bool $claimAnswers = true;

    /** @var list<string> */
    private array $expired = [];

    /** @var array<string, mixed> */
    private array $handedBack = [];

    // ---------------------------------------------------------------- paid()

    // The step the whole tunnel rests on: reaching the return url without having paid delivers nothing
    public function testABasketWithNoConfirmedPaymentIsNotDelivered(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->service()->paid($basket);

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
        $this->assertNull($this->dispatched);
    }

    // The same basket, once its provider has confirmed the charge
    public function testABasketWithAConfirmedPaymentIsDelivered(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));

        $this->service()->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertInstanceOf(ConfirmOrderMessage::class, $this->dispatched?->getMessage());
    }

    // A basket with nothing to pay has no payment to confirm, and is the one case that goes through on its own
    public function testAFreeBasketIsDeliveredWithoutAnyPayment(): void
    {
        $basket = $this->basket(0, null);

        $this->service()->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // The webhook and the customer's return land within the same second; the database hands the basket to one of them, and the loser delivers nothing
    public function testTheRequestThatLosesTheClaimDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $this->claimAnswers = false;

        $this->service()->paid($basket);

        $this->assertSame([], $this->delivered);
        $this->assertNull($this->dispatched);
    }

    // The one place an order is numbered, and the only path the database lets through once
    public function testTheOrderIsInvoicedWhenItIsDelivered(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())->method('assign')->with($basket);

        $this->service(invoiceService: $invoiceService)->paid($basket);
    }

    // A basket the claim went against is not invoiced either: a number drawn for an order somebody else delivered is a gap in the sequence
    public function testTheRequestThatLosesTheClaimInvoicesNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $this->claimAnswers = false;

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->never())->method('assign');

        $this->service(invoiceService: $invoiceService)->paid($basket);
    }

    // A basket already delivered is not delivered twice, whichever path asks
    public function testAnAlreadyPaidBasketIsNotDeliveredAgain(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus(BasketStatus::Paid);

        $this->service()->paid($basket);

        $this->assertSame([], $this->delivered);
    }

    // ------------------------------------------------------ applyNotification()

    // The webhook's own step: it records the charge and delivers, rather than waiting for a customer who may never come back
    public function testAConfirmedNotificationRecordsThePaymentAndDelivers(): void
    {
        $payment = $this->payment(2500, finished: false);
        $basket = $this->basket(2500, $payment);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertTrue($payment->isFinished());
        $this->assertSame('pi_1', $payment->getTransactionId());
        $this->assertSame('card', $payment->getPaymentMethod());
        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // The checkout is opened for what is left to pay, so the charge is compared to that and not to the undiscounted total - an order settled with a code or a gift card would otherwise be refused every time
    public function testANotificationChargingTheDiscountedAmountDelivers(): void
    {
        $payment = $this->payment(2000, finished: false);
        $basket = $this->basket(2500, $payment);
        $basket->setDiscountCode('NOEL');
        $basket->setDiscountAmount(500);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2000));

        $this->assertTrue($payment->isFinished());
        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // A basket can still be added to once it has been validated, and the checkout the customer paid was priced before that
    public function testANotificationChargingLessThanTheBasketDeliversNothing(): void
    {
        $payment = $this->payment(9900, finished: false);
        $basket = $this->basket(9900, $payment);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertFalse($payment->isFinished());
        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // The attack the check is there for: the basket is added to after it was validated, and the customer pays the checkout priced before that. Both the payment row and the charge read the old price and agree with each other - only what the basket holds now tells them apart
    public function testANotificationMatchingThePaymentButNotTheGrownBasketDeliversNothing(): void
    {
        $payment = $this->payment(2500, finished: false);
        $basket = $this->basket(2500, $payment);
        $basket->setTotal(9900);
        $basket->setItems(['product' => [1 => [
            'quantity' => 4,
            'total' => 9900,
            'parent' => ['title' => 'Un livre'],
            'item' => ['title' => 'Broché', 'price' => 2475],
        ]]]);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertFalse($payment->isFinished());
        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // Raising here would have the provider replay the same notification for days over a basket that is not coming back
    public function testANotificationNamingAnUnknownBasketIsDroppedRatherThanRaised(): void
    {
        $this->service(null)->applyNotification(new PaymentNotification('404', 'stripe', 'pi_1', 'card', 2500));

        $this->assertSame([], $this->delivered);
    }

    // Money going back is handed to the refund service whole, and never delivers anything - not even a basket still waiting for its payment
    public function testARefundNotificationIsHandedOverAndDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $notification = new PaymentNotification(null, 'stripe', 'pi_1', null, 2500, PaymentEvent::Refunded);
        $refundService = $this->createMock(BasketRefundService::class);
        $refundService->expects($this->once())->method('apply')->with($notification);

        $this->service($basket, refundService: $refundService)->applyNotification($notification);

        $this->assertSame([], $this->delivered);
    }

    // --------------------------------------------------------- confirmReturn()

    // The customer's return is confirmed with the provider itself, never taken at face value
    public function testTheReturnIsConfirmedWithTheProviderAndDelivers(): void
    {
        $payment = $this->payment(2500, finished: false);
        $basket = $this->basket(2500, $payment);
        $gateway = $this->returnAwareGateway(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->service($basket, $gateway)->confirmReturn($basket, new Request(['session_id' => 'cs_1']));

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // The provider answering about another basket confirms nothing on this one
    public function testAReturnConfirmingAnotherBasketDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->returnAwareGateway(new PaymentNotification('99', 'stripe', 'pi_1', 'card', 2500));

        $this->service($basket, $gateway)->confirmReturn($basket, new Request(['session_id' => 'cs_1']));

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // The provider says the money has not arrived: the page shows the order as it stands and the webhook settles it later
    public function testAReturnTheProviderDoesNotConfirmDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->returnAwareGateway(null);

        $this->service($basket, $gateway)->confirmReturn($basket, new Request());

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // Stripe being unreachable must not turn the customer's return into an error page: its webhook confirms the same payment on its own
    public function testAProviderThatCannotBeReachedLeavesTheBasketToTheWebhook(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('readReturn')->willThrowException(new \RuntimeException('Stripe is down'));

        $this->service($basket, $gateway)->confirmReturn($basket, new Request(['session_id' => 'cs_1']));

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // The webhook may have delivered the basket before the customer got back; the order is no longer their current basket either way
    public function testAnOrderIsTakenOutOfTheVisitorsSession(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus(BasketStatus::Paid);
        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', 42);

        $this->service($basket, null, $session)->confirmReturn($basket, new Request());

        $this->assertFalse($session->has('basket'));
    }

    // A basket still being paid for stays the visitor's own
    public function testAnUnconfirmedBasketStaysInTheVisitorsSession(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', 42);

        $this->service($basket, $this->returnAwareGateway(null), $session)->confirmReturn($basket, new Request());

        $this->assertTrue($session->has('basket'));
    }

    // On a shared order the visitor coming back is the payer and not the customer: their own basket is none of this order's business, and dropping it would take the recovery cookie with it (see BasketRecoverySubscriber)
    public function testTheOrderOfSomebodyElseLeavesThePayersOwnBasketAlone(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus(BasketStatus::Paid);

        $session = $this->sessionNaming(77);

        $this->service($basket, null, $session)->confirmReturn($basket, new Request());

        $this->assertSame(77, $session->get('basket'), 'The payer keeps the basket their own session named');
    }

    // ------------------------------------------- editing a checkout in flight

    // The checkout the customer is looking at was priced before this edit: the basket goes back to being a basket, so the session left open at the provider can no longer deliver anything
    public function testEditingAValidatedBasketPutsItBackToABasket(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->service($basket, null, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // ... and the payment that arrives for that abandoned checkout delivers nothing, the basket no longer being one anybody validated
    public function testTheCheckoutLeftOpenOnAnEditedBasketDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus(BasketStatus::New);

        $this->service($basket)->paid($basket);

        $this->assertSame([], $this->delivered);
    }

    // A customer who paid and never came back leaves the order named by their session; it is an order, not something to add to
    public function testAnOrderIsNotEditedByTheSessionThatStillNamesIt(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus(BasketStatus::Paid);
        $ordered = $basket->getItems();

        $this->service($basket, null, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame($ordered, $basket->getItems());

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertArrayHasKey(1, $basket->getItems()['product']);
    }

    // The customer still has the checkout open in a tab; editing the basket calls it off at the provider, so paying it is no longer possible - refusing that payment afterwards would leave them charged and empty-handed
    public function testEditingAValidatedBasketCallsOffTheCheckoutItHadOpened(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(['cs_1'], $this->expired);
        $this->assertNull($basket->getPayment()?->getGatewayReference());
    }

    // A checkout the provider cannot be reached about still reopens the basket: the payment that may yet arrive is refused on its amount
    public function testAProviderThatRefusesToExpireStillReopensTheBasket(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('expireCheckout')->willThrowException(new \RuntimeException('Session already expired'));

        $this->service($basket, $gateway, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // A provider refusing to call off a checkout already paid, the webhook still on its way: the customer back on the basket by the browser's button
    private function paidCheckoutGateway(PaymentNotification $notification): TestReturnAwareGateway
    {
        $gateway = $this->createMock(TestReturnAwareGateway::class);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('isConfigured')->willReturn(true);
        $gateway->method('expireCheckout')->willThrowException(new \RuntimeException('Only open sessions can be expired'));
        $gateway->method('readReturn')->willReturnCallback(function (Request $request, ?string $reference) use ($notification): PaymentNotification {
            $this->assertSame('cs_1', $reference);

            return $notification;
        });
        $gateway->expects($this->never())->method('createCheckout');

        return $gateway;
    }

    private function paidNotification(int $amount = 2500, string $basketId = '42'): PaymentNotification
    {
        return new PaymentNotification($basketId, 'stripe', 'pi_1', 'card', $amount);
    }

    private function routeNamingUrls(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        return $urlGenerator;
    }

    // Validating again what was just paid delivers the existing order, and sends the customer to it rather than to a second checkout
    public function testValidatingAgainAfterPayingSettlesTheOrderAndOpensNoCheckout(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $service = $this->service($basket, $this->paidCheckoutGateway($this->paidNotification()), $this->sessionNaming(42), urlGenerator: $this->routeNamingUrls());

        $this->assertSame('/basket_paid', $service->validate(new Request()));

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertTrue($basket->getPayment()->isFinished());
        $this->assertSame('pi_1', $basket->getPayment()->getTransactionId());
        $this->assertSame(['product'], $this->delivered);
    }

    // Followed twice by a payer who has paid: the same, and to the payer's own page
    public function testASharedLinkFollowedAfterPayingSettlesTheOrderAndOpensNoCheckout(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setShareToken('1111222233334444');

        $service = $this->service($basket, $this->paidCheckoutGateway($this->paidNotification()), urlGenerator: $this->routeNamingUrls());

        $this->assertSame('/basket_shared_paid', $service->payShared($basket));
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // Editing the basket after paying: nothing is taken out of the order, and it is delivered
    public function testEditingAfterPayingSettlesTheOrderInsteadOfReopeningIt(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->service($basket, $this->paidCheckoutGateway($this->paidNotification()), $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertArrayHasKey(1, $basket->getItems()['product']);
    }

    // Emptying the basket after paying leaves the order standing
    public function testDeletingAfterPayingKeepsTheOrder(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $this->service($basket, $this->paidCheckoutGateway($this->paidNotification()), $this->sessionNaming(42), entityManager: $entityManager)->delete();

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // What the back-office asks before deleting: the checkout is called off, so a tab left open can no longer charge for a basket gone
    public function testCallingOffACheckoutExpiresItAtTheProvider(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->assertTrue($this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->callOffCheckout($basket));

        $this->assertSame(['cs_1'], $this->expired);
        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // Found paid on the way, the order is settled and the back-office told to keep it
    public function testCallingOffACheckoutFoundPaidKeepsTheOrder(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $this->assertFalse($this->service($basket, $this->paidCheckoutGateway($this->paidNotification()), $this->sessionNaming(42))->callOffCheckout($basket));

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // The provider says paid, the order disagrees on the amount: nothing is delivered and the shop told, as on any notification, and no second checkout charges on top
    public function testAPaymentTheOrderRefusesStillOpensNoSecondCheckout(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));

        $service = $this->service($basket, $this->paidCheckoutGateway($this->paidNotification(1)), $this->sessionNaming(42), urlGenerator: $this->routeNamingUrls());

        $this->assertSame('/basket_paid', $service->validate(new Request()));
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
        $this->assertFalse($basket->getPayment()->isFinished());
    }

    // Refused for another reason, the session having expired: read back unpaid, the basket reopens as it always did
    public function testACheckoutReadBackUnpaidStillReopensTheBasket(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('expireCheckout')->willThrowException(new \RuntimeException('Session already expired'));
        $gateway->method('readReturn')->willReturn(null);

        $this->service($basket, $gateway, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
        $this->assertNull($basket->getPayment()->getGatewayReference());
    }

    // A session paid for another basket confirms nothing about this one
    public function testACheckoutReadBackForAnotherBasketStillReopensThisOne(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('expireCheckout')->willThrowException(new \RuntimeException('Refused'));
        $gateway->method('readReturn')->willReturn($this->paidNotification(basketId: '43'));

        $this->service($basket, $gateway, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // The provider out of reach on both calls: nothing is known, the basket reopens as it always did
    public function testAProviderOutOfReachOnBothCallsStillReopensTheBasket(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('expireCheckout')->willThrowException(new \RuntimeException('Down'));
        $gateway->method('readReturn')->willThrowException(new \RuntimeException('Down'));

        $this->service($basket, $gateway, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // A checkout called off without a fuss is asked nothing more
    public function testACheckoutCalledOffIsNotReadBack(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createMock(TestReturnAwareGateway::class);
        $gateway->expects($this->once())->method('expireCheckout');
        $gateway->expects($this->never())->method('readReturn');
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('isConfigured')->willReturn(true);

        $this->service($basket, $gateway, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // A payment already settled is no longer a checkout to call off
    public function testASettledPaymentIsNotExpired(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame([], $this->expired);
    }

    // The provider hands its data over at validation and gets it back at delivery, the webhook carrying no session of the customer to read it from
    public function testWhatAProviderHandsOverAtValidationIsKeptOnTheBasket(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setStatus(BasketStatus::New);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());

        $this->assertSame(['product' => ['contributor' => 'Camille']], $basket->getCheckoutData());
    }

    /**
     * The mode the checkout is opened in, stamped on the order as it is frozen.
     *
     * Read afterwards by InvoiceService::assign(), which bills no number to an order charged against the
     * provider's test keys - and read from the order rather than from the toggle, which can be flipped back
     * between the moment an order is validated and the moment it is paid.
     */
    public function testAnOrderRemembersTheModeItsCheckoutWasOpenedIn(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setStatus(BasketStatus::New);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());
        $this->assertFalse($basket->isTestMode());

        $rehearsed = $this->basket(2500, $this->payment(2500, finished: false));
        $rehearsed->setStatus(BasketStatus::New);

        $this->service($rehearsed, $this->returnAwareGateway(null), $this->sessionNaming(42), testMode: true)->validate(new Request());
        $this->assertTrue($rehearsed->isTestMode());
    }

    // The waiver the checkout made the customer tick is dated on their order as the proof of it; an order they share is waived by whoever pays it, and dated then
    public function testTheWithdrawalWaiverIsDatedOnWhoeverPays(): void
    {
        $this->providerFlags = Basket::CONTENT_FLAG_DIGITAL | Basket::CONTENT_FLAG_PHYSICAL;
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setStatus(BasketStatus::New);
        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());
        $this->assertNotNull($basket->getWithdrawalWaived());

        $shared = $this->basket(2500, $this->payment(2500, finished: false));
        $shared->setStatus(BasketStatus::New);
        $this->service($shared, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request(), true);
        $this->assertNull($shared->getWithdrawalWaived());

        $shared->setStatus(BasketStatus::Validated)->setShareToken('1111222233334444');
        $this->service($shared, $this->returnAwareGateway(null))->payShared($shared);
        $this->assertNotNull($shared->getWithdrawalWaived());
    }

    // An order covered in full by a code or a gift card is delivered exactly like a paid one: the free path used to return before this loop, and every provider was handed an empty array when the order was delivered
    public function testWhatAProviderHandsOverIsKeptOnAnOrderWithNothingLeftToPay(): void
    {
        $basket = $this->basket(0, null);
        $basket->setStatus(BasketStatus::New);

        $this->service($basket, null, $this->sessionNaming(42))->validate(new Request());

        $this->assertSame(['product' => ['contributor' => 'Camille']], $basket->getCheckoutData());
    }

    // The code was read when the basket was last touched, which can be days ago: one turned off or expired since then is taken off the order rather than left lowering what it is charged, and the customer is sent back to a basket saying the true price
    public function testACodeThatStoppedApplyingRefusesTheOrderAndLeavesTheBasket(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setDiscountCode('SOLDES')->setDiscountKind('percentage')->setDiscountAmount(500);

        try {
            $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());
            $this->fail('An order carrying a code that no longer applies is refused');
        } catch (BasketNotOrderableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($basket->getDiscountCode(), 'The code goes, or the same refusal comes back forever');
        $this->assertSame(0, $basket->getDiscountAmount());
        $this->assertSame(BasketStatus::New, $basket->getStatus(), 'Nothing was frozen: the basket is the one the customer left');
    }

    // The slug arrives off a form: one naming a provider the shop does not offer - keys cleared since the page was drawn, or simply typed in - would open a checkout the shop cannot be paid through
    public function testAGatewayNamedButNotOfferedFallsBackOnTheShopsOwn(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setStatus(BasketStatus::New);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))
            ->validate(new Request([], ['gateway' => 'paypal']));

        $this->assertSame('stripe', $basket->getPayment()->getGateway());
    }

    // The payer of a shared order is given no choice of their own: the order is settled through the provider it was opened with, and falls back on the shop's own once that provider holds no key
    public function testASharedOrderIsSettledThroughTheProviderItWasOpenedWith(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->getPayment()->setGateway('revolut');
        $basket->setShareToken('1111222233334444');

        $this->service($basket, $this->returnAwareGateway(null))->payShared($basket);

        // The checkout that was open is called off through the provider that opened it, before the row names another
        $this->assertSame(['cs_1'], $this->expired);
        $this->assertSame('stripe', $basket->getPayment()->getGateway());
    }

    // Delivery hands it back, and the webhook is the path that has nowhere else to read it from
    public function testTheWebhookHandsTheCheckoutDataBackToTheProvider(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setCheckoutData(['product' => ['contributor' => 'Camille']]);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertSame(['contributor' => 'Camille'], $this->handedBack);
    }

    // A delivered basket is no longer a checkout, and some of what was carried across it is the customer's own details
    public function testADeliveredBasketKeepsNoCheckoutData(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setCheckoutData(['product' => ['contributor' => 'Camille']]);

        $this->service($basket)->paid($basket);

        $this->assertSame([], $basket->getCheckoutData());
    }

    // Editing the basket calls the checkout off, and what was handed over for it goes with it
    public function testEditingAValidatedBasketDropsWhatWasHandedOverForItsCheckout(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setCheckoutData(['product' => ['contributor' => 'Camille']]);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame([], $basket->getCheckoutData());
    }

    // Basket holds exactly one Payment: a checkout abandoned, edited and started again writes that row over rather than orphaning it
    public function testAnAbandonedCheckoutStartedAgainReusesItsPaymentRow(): void
    {
        // The basket holds 9900 where its open checkout was opened at 2500: validate() counts it again before numbering it, so what the row carries is what the customer is charged
        $payment = $this->payment(2500, finished: false);
        $basket = $this->basket(9900, $payment);
        $basket->setStatus(BasketStatus::New);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());

        $this->assertSame($payment, $basket->getPayment());
        $this->assertSame(9900, $payment->getAmount());
        $this->assertSame('cs_1', $payment->getGatewayReference());
    }

    // The whole point of counting the basket again in validate(): until the coordinates form ran, the delivery was priced on the country the shop posts to by default, and the order has to be charged for where it actually goes
    // Written on the basket and refused rather than charged: no page of the site ever showed that price, and the basket the customer comes back to now says it
    public function testDeliveryCostingMoreToTheAddressGivenIsWrittenAndRefused(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        // What CoordinatesType has just written on it, the basket page having priced the parcel on "shop-shipping-country" before that
        $basket->setCountry('JP');

        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (?string $country): ?int => 'JP' === $country ? 2990 : 490);

        try {
            $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $resolver)
                ->validate(new Request());
            $this->fail('The order was charged for a delivery the customer was never shown');
        } catch (BasketNotOrderableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2990, $basket->getShipping());
        // Nothing validated, nothing charged: the customer validates again on the total the basket now says
        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // The estimate holding is the ordinary case, and it must not be refused: the basket page priced the parcel on the country the address turns out to name
    public function testDeliveryCostingWhatWasEstimatedGoesThrough(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setShipping(490);
        $basket->setCountry('FR');

        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('resolve')->willReturn(490);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $resolver)
            ->validate(new Request());

        $this->assertSame(490, $basket->getShipping());
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // A parcel heavier than its zone's last tier takes no order rather than posting free, the shop never having said what it costs - and the zone is the address's, not the shop's default one
    public function testAParcelHeavierThanTheZoneOfItsAddressIsRefused(): void
    {
        $this->config = ['shop-shipping-country' => 'FR'];
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setCountry('DE');

        try {
            $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $this->germanyStopsShort())
                ->validate(new Request());
            $this->fail('A parcel no tier takes was posted free');
        } catch (BasketNotOrderableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // The same parcel goes through to a country whose zone takes it, the default one stopping short being only an estimate
    public function testAParcelTheZoneOfItsAddressTakesGoesThrough(): void
    {
        $this->config = ['shop-shipping-country' => 'DE'];
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setShipping(490);
        $basket->setCountry('FR');

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $this->germanyStopsShort())
            ->validate(new Request());

        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // Free shipping does not lift the carrier's limit: a large basket does not make a parcel no tier takes any lighter
    public function testAParcelAboveTheFreeShippingThresholdIsRefusedAllTheSame(): void
    {
        $this->config = ['shop-shipping-free' => 1000];
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setCountry('DE');

        $this->expectException(BasketNotOrderableException::class);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $this->germanyStopsShort())
            ->validate(new Request());
    }

    // The basket page is told before the checkout, from the json it loads with and not only after a change
    public function testTheBasketPageIsToldTheParcelIsTooHeavy(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setContentFlags(Basket::CONTENT_FLAG_PHYSICAL);
        $basket->setCountry('DE');

        $json = $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42), shippingRateResolver: $this->germanyStopsShort())
            ->getJson();

        $this->assertTrue($json['basket']['shippingTooHeavy']);
    }

    // ------------------------------------------------------- stored quantities

    // A line stored below one, from before addItem() refused them: whatever its provider says, it does not go through, the free path included
    /** @return iterable<string, array{int|string}> */
    public static function storedQuantitiesBelowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-2];
        yield 'zero as a string' => ['0'];
    }

    #[DataProvider('storedQuantitiesBelowOne')]
    public function testAStoredLineBelowOneRefusesTheOrder(int | string $quantity): void
    {
        $basket = $this->basket(0, null);
        $basket->setStatus(BasketStatus::New);
        $basket->setItems(['card' => [3 => ['quantity' => $quantity, 'total' => 0, 'item' => ['title' => 'Back', 'price' => 500]]]]);

        $this->expectException(BasketNotOrderableException::class);
        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate($this->jsonRequest([]));
    }

    // The same line on an order numbered before the fix: its free path, or a total it brought down, delivers nothing
    public function testAStoredLineBelowOneIsNotDelivered(): void
    {
        $basket = $this->basket(0, null);
        $basket->setItems(['product' => [1 => ['quantity' => 0, 'total' => 0, 'item' => ['title' => 'Book', 'price' => 500]]]]);

        $this->service($basket)->paid($basket);

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // A truly free article, quantity one: the free path it has always taken
    public function testAFreeLineOfQuantityOneGoesThroughAndIsDelivered(): void
    {
        $basket = $this->basket(0, null);
        $basket->setStatus(BasketStatus::New);

        $url = $this->service($basket, null, $this->sessionNaming(42))->validate($this->jsonRequest([]));
        $this->service($basket)->paid($basket);

        $this->assertIsString($url);
        $this->assertSame(['product'], $this->delivered);
    }

    // ----------------------------------------------- an order is settled once

    // The order paid or refunded, still named by the session: nothing renumbers it, and the visitor starts over
    /** @return iterable<string, array{BasketStatus}> */
    public static function recordedStatuses(): iterable
    {
        yield 'paid' => [BasketStatus::Paid];
        yield 'shipped' => [BasketStatus::Shipped];
        yield 'refunded' => [BasketStatus::Refunded];
    }

    #[DataProvider('recordedStatuses')]
    public function testARecordedOrderIsNotValidatedAgain(BasketStatus $status): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus($status);
        $session = $this->sessionNaming(42);

        try {
            $this->service($basket, $this->returnAwareGateway(null), $session)->validate($this->jsonRequest([]));
            $this->fail('An order is not validated again');
        } catch (BasketNotOrderableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($status, $basket->getStatus());
        $this->assertSame('202608-AB-12345', $basket->getNumber());
        $this->assertTrue($basket->getPayment()->isFinished());
        $this->assertFalse($session->has('basket'), 'The session lets go of it, and the next page starts a new basket');
    }

    // A session the provider says is paid, already recorded on this very payment: it settled once. Reset since by a checkout started again, it settles nothing more
    public function testATransactionAlreadyRecordedDoesNotSettleAgain(): void
    {
        $payment = $this->payment(2500, finished: false)->setTransactionId('pi_1');
        $basket = $this->basket(2500, $payment);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertSame([], $this->delivered);
        $this->assertFalse($payment->isFinished());
        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // The replay of a confirmation on an order whose delivery failed is what retries it, and is let through
    public function testTheReplayOfAChargedUndeliveredOrderDeliversIt(): void
    {
        $payment = $this->payment(2500, finished: true)->setTransactionId('pi_1');
        $basket = $this->basket(2500, $payment);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertSame(['product'], $this->delivered);
    }

    // The return of an order charged and not delivered is a retry that needs nothing of the provider, whose reference is long gone
    public function testAReturnOnAChargedOrderRetriesWithoutAskingTheProvider(): void
    {
        $payment = $this->payment(2500, finished: true);
        $payment->setGatewayReference(null);
        $basket = $this->basket(2500, $payment);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->confirmReturn($basket, new Request());

        $this->assertSame(['product'], $this->delivered);
    }

    // A shared order already charged asks nobody for money again, nor does its row start over
    public function testAChargedSharedOrderOpensNoSecondCheckout(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setShareToken('0123456789abcdef');

        $this->expectException(PaymentUnavailableException::class);
        $this->service($basket, $this->returnAwareGateway(null))->payShared($basket);
    }

    // ------------------------------------------------------- deleting a basket

    // Emptying the page on an order the session still names only lets go of it: the row is the shop's record
    /** @return iterable<string, array{BasketStatus, bool}> */
    public static function orders(): iterable
    {
        yield 'paid' => [BasketStatus::Paid, true];
        yield 'shipped' => [BasketStatus::Shipped, true];
        yield 'refunded' => [BasketStatus::Refunded, true];
        yield 'charged and undelivered' => [BasketStatus::Validated, true];
    }

    #[DataProvider('orders')]
    public function testDeletingAnOrderOnlyClearsTheSession(BasketStatus $status, bool $finished): void
    {
        $basket = $this->basket(2500, $this->payment(2500, $finished));
        $basket->setStatus($status);
        $session = $this->sessionNaming(42);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $answer = $this->service($basket, null, $session, entityManager: $entityManager)->delete();

        $this->assertSame(['total' => 0, 'quantity' => 0], $answer);
        $this->assertFalse($session->has('basket'));
    }

    // A basket still being filled goes as it always did
    public function testDeletingABasketRemovesIt(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus(BasketStatus::New);
        $session = $this->sessionNaming(42);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($basket);

        $this->service($basket, null, $session, entityManager: $entityManager)->delete();

        $this->assertFalse($session->has('basket'));
    }

    // A session naming a row the nightly pass has taken away has nothing to remove, and no crash to answer with
    public function testDeletingABasketThatIsGoneOnlyClearsTheSession(): void
    {
        $session = $this->sessionNaming(42);

        $this->service(null, null, $session)->delete();

        $this->assertFalse($session->has('basket'));
    }

    // ------------------------------------------------------------------ setup

    // A grid whose German zone stops short of every parcel, the other countries pricing it at 490
    private function germanyStopsShort(): ShippingRateResolverInterface
    {
        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (?string $country): ?int => 'DE' === $country ? null : 490);
        $resolver->method('exceeds')->willReturnCallback(static fn (?string $country): bool => 'DE' === $country);

        return $resolver;
    }

    private function sessionNaming(int $id): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', $id);

        return $session;
    }

    private function basket(int $total, ?Payment $payment): Basket
    {
        $basket = new Basket();
        $basket->setStatus(BasketStatus::Validated);
        $basket->setTotal($total);
        $basket->setShipping(0);
        $basket->setCurrency('EUR');
        $basket->setNumber('202608-AB-12345');
        // Every order taken from the front carries one, CoordinatesType asking for it - and the confirmation is only sent to an order that names somebody
        $basket->setEmail('marie@example.org');
        $basket->setItems(['product' => [1 => [
            'quantity' => 1,
            'total' => $total,
            'parent' => ['title' => 'Un livre'],
            'item' => ['title' => 'Broché', 'price' => $total],
        ]]]);

        if (null !== $payment) {
            $basket->setPayment($payment);
        }

        // The id a persisted basket would carry, which the notification names it by
        $id = new \ReflectionProperty(Basket::class, 'id');
        $id->setValue($basket, 42);

        return $basket;
    }

    private function payment(int $amount, bool $finished): Payment
    {
        $payment = new Payment();
        $payment->setGateway('stripe');
        $payment->setGatewayReference('cs_1');
        $payment->setAmount($amount);
        $payment->setCurrency('EUR');
        $payment->setFinished($finished);

        return $payment;
    }

    private function returnAwareGateway(?PaymentNotification $notification): TestReturnAwareGateway
    {
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('readReturn')->willReturn($notification);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('isConfigured')->willReturn(true);
        $gateway->method('createCheckout')->willReturn(new CheckoutSession('https://checkout.stripe.com/c/pay/cs_1', 'cs_1'));
        $gateway->method('expireCheckout')->willReturnCallback(function (string $reference): void {
            $this->expired[] = $reference;
        });

        return $gateway;
    }

    private function jsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function paymentTestMode(bool $enabled): PaymentTestModeInterface
    {
        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($enabled);

        return $testMode;
    }

    private function service(?Basket $basket = null, ?object $gateway = null, ?Session $session = null, ?InvoiceService $invoiceService = null, bool $testMode = false, ?ShippingRateResolverInterface $shippingRateResolver = null, ?BasketRefundService $refundService = null, ?EntityManagerInterface $entityManager = null, ?UrlGeneratorInterface $urlGenerator = null): BasketService
    {
        // The one collaborator two of them are handed: the service prices the order through it, and so does the calculator
        $itemProviderRegistry = $this->itemProviderRegistry();

        return new BasketService(
            $this->basketRepository($basket),
            $this->configService(),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->requestStack($session),
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->messageBus(),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $this->gatewayRegistry($gateway),
            $this->paymentTestMode($testMode),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class), $this->createStub(BasketRepository::class)),
            new VatCalculator($itemProviderRegistry),
            $invoiceService ?? $this->createStub(InvoiceService::class),
            $shippingRateResolver ?? $this->createStub(ShippingRateResolverInterface::class),
            $refundService ?? $this->createStub(BasketRefundService::class),
        );
    }

    // The row the session names, and the database answering whether this call is the one that moved it to paid
    private function basketRepository(?Basket $basket): BasketRepository
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('find')->willReturn($basket);
        $basketRepository->method('claimPaid')->willReturnCallback(function (Basket $claimed): bool {
            // The database moves the row, or answers that somebody else already did
            if ($this->claimAnswers) {
                $claimed->setStatus(BasketStatus::Paid);
            }

            return $this->claimAnswers;
        });

        return $basketRepository;
    }

    // Each provider's own delivery effects, recorded rather than run
    private function itemProviderRegistry(): BasketItemProviderRegistry
    {
        $provider = $this->createStub(\c975L\PaymentBundle\Contract\BasketItemProviderInterface::class);
        $provider->method('onBasketValidated')->willReturn(['contributor' => 'Camille']);
        $provider->method('getContentFlags')->willReturnCallback(fn (): int => $this->providerFlags);
        $provider->method('onBasketPaid')->willReturnCallback(function (Basket $basket, array $items, array $checkoutData): void {
            $this->delivered[] = 'product';
            $this->handedBack = $checkoutData;
        });

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);

        return $itemProviderRegistry;
    }

    // A shop offering the one gateway it was given, and none at all when it was given null
    private function gatewayRegistry(?object $gateway): PaymentGatewayRegistry
    {
        $gatewayRegistry = $this->createStub(PaymentGatewayRegistry::class);
        $gatewayRegistry->method('getActiveOrNull')->willReturn($gateway);
        $gatewayRegistry->method('getOffered')->willReturn(null !== $gateway ? [$gateway->getSlug() => $gateway] : []);
        $gatewayRegistry->method('has')->willReturn(null !== $gateway);
        if (null !== $gateway) {
            $gatewayRegistry->method('getActive')->willReturn($gateway);
            $gatewayRegistry->method('get')->willReturn($gateway);
        }

        return $gatewayRegistry;
    }

    // What the service dispatches is kept rather than handled, for the test to read back
    private function messageBus(): MessageBusInterface
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(fn (object $message): Envelope => $this->dispatched = new Envelope($message));

        return $messageBus;
    }

    // A request carrying the session, or no request at all when the test hands over none
    private function requestStack(?Session $session): RequestStack
    {
        $requestStack = new RequestStack();
        if (null !== $session) {
            $request = new Request();
            $request->setSession($session);
            $requestStack->push($request);
        }

        return $requestStack;
    }

    // The shop's currency, everything else being counted in cents
    private function configService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => $this->config[$slug] ?? ('shop-currency' === $slug ? 'EUR' : 0));

        return $configService;
    }
}

// A gateway that both charges and reads the customer's return, which is what a stub has to implement for BasketService to ask it
abstract class TestReturnAwareGateway implements \c975L\PaymentBundle\Contract\PaymentGatewayInterface, ExpirableGatewayInterface, ReturnAwareGatewayInterface
{
}
