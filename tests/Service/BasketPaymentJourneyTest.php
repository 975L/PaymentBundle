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
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->assertSame('validated', $basket->getStatus());
        $this->assertNull($this->dispatched);
    }

    // The same basket, once its provider has confirmed the charge
    public function testABasketWithAConfirmedPaymentIsDelivered(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));

        $this->service()->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame('paid', $basket->getStatus());
        $this->assertInstanceOf(ConfirmOrderMessage::class, $this->dispatched?->getMessage());
    }

    // A basket with nothing to pay has no payment to confirm, and is the one case that goes through on its own
    public function testAFreeBasketIsDeliveredWithoutAnyPayment(): void
    {
        $basket = $this->basket(0, null);

        $this->service()->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame('paid', $basket->getStatus());
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
        $basket->setStatus('paid');

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
        $this->assertSame('paid', $basket->getStatus());
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
        $this->assertSame('paid', $basket->getStatus());
    }

    // A basket can still be added to once it has been validated, and the checkout the customer paid was priced before that
    public function testANotificationChargingLessThanTheBasketDeliversNothing(): void
    {
        $payment = $this->payment(9900, finished: false);
        $basket = $this->basket(9900, $payment);

        $this->service($basket)->applyNotification(new PaymentNotification('42', 'stripe', 'pi_1', 'card', 2500));

        $this->assertFalse($payment->isFinished());
        $this->assertSame([], $this->delivered);
        $this->assertSame('validated', $basket->getStatus());
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
        $this->assertSame('validated', $basket->getStatus());
    }

    // Raising here would have the provider replay the same notification for days over a basket that is not coming back
    public function testANotificationNamingAnUnknownBasketIsDroppedRatherThanRaised(): void
    {
        $this->service(null)->applyNotification(new PaymentNotification('404', 'stripe', 'pi_1', 'card', 2500));

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
        $this->assertSame('paid', $basket->getStatus());
    }

    // The provider answering about another basket confirms nothing on this one
    public function testAReturnConfirmingAnotherBasketDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->returnAwareGateway(new PaymentNotification('99', 'stripe', 'pi_1', 'card', 2500));

        $this->service($basket, $gateway)->confirmReturn($basket, new Request(['session_id' => 'cs_1']));

        $this->assertSame([], $this->delivered);
        $this->assertSame('validated', $basket->getStatus());
    }

    // The provider says the money has not arrived: the page shows the order as it stands and the webhook settles it later
    public function testAReturnTheProviderDoesNotConfirmDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->returnAwareGateway(null);

        $this->service($basket, $gateway)->confirmReturn($basket, new Request());

        $this->assertSame([], $this->delivered);
        $this->assertSame('validated', $basket->getStatus());
    }

    // Stripe being unreachable must not turn the customer's return into an error page: its webhook confirms the same payment on its own
    public function testAProviderThatCannotBeReachedLeavesTheBasketToTheWebhook(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $gateway = $this->createStub(TestReturnAwareGateway::class);
        $gateway->method('readReturn')->willThrowException(new \RuntimeException('Stripe is down'));

        $this->service($basket, $gateway)->confirmReturn($basket, new Request(['session_id' => 'cs_1']));

        $this->assertSame([], $this->delivered);
        $this->assertSame('validated', $basket->getStatus());
    }

    // The webhook may have delivered the basket before the customer got back; the order is no longer their current basket either way
    public function testAnOrderIsTakenOutOfTheVisitorsSession(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus('paid');
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
        $basket->setStatus('paid');

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

        $this->assertSame('new', $basket->getStatus());
    }

    // ... and the payment that arrives for that abandoned checkout delivers nothing, the basket no longer being one anybody validated
    public function testTheCheckoutLeftOpenOnAnEditedBasketDeliversNothing(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus('new');

        $this->service($basket)->paid($basket);

        $this->assertSame([], $this->delivered);
    }

    // A customer who paid and never came back leaves the order named by their session; it is an order, not something to add to
    public function testAnOrderIsNotEditedByTheSessionThatStillNamesIt(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: true));
        $basket->setStatus('paid');
        $ordered = $basket->getItems();

        $this->service($basket, null, $this->sessionNaming(42))
            ->deleteItem($this->jsonRequest(['type' => 'product', 'id' => 1]));

        $this->assertSame($ordered, $basket->getItems());

        $this->assertSame('paid', $basket->getStatus());
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

        $this->assertSame('new', $basket->getStatus());
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
        $basket->setStatus('new');

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
        $basket->setStatus('new');

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());
        $this->assertFalse($basket->isTestMode());

        $rehearsed = $this->basket(2500, $this->payment(2500, finished: false));
        $rehearsed->setStatus('new');

        $this->service($rehearsed, $this->returnAwareGateway(null), $this->sessionNaming(42), testMode: true)->validate(new Request());
        $this->assertTrue($rehearsed->isTestMode());
    }

    // An order covered in full by a code or a gift card is delivered exactly like a paid one: the free path used to return before this loop, and every provider was handed an empty array when the order was delivered
    public function testWhatAProviderHandsOverIsKeptOnAnOrderWithNothingLeftToPay(): void
    {
        $basket = $this->basket(0, null);
        $basket->setStatus('new');

        $this->service($basket, null, $this->sessionNaming(42))->validate(new Request());

        $this->assertSame(['product' => ['contributor' => 'Camille']], $basket->getCheckoutData());
    }

    // The code was read when the basket was last touched, which can be days ago: one turned off or expired since then is taken off the order rather than left lowering what it is charged, and the customer is sent back to a basket saying the true price
    public function testACodeThatStoppedApplyingRefusesTheOrderAndLeavesTheBasket(): void
    {
        $basket = $this->basket(2500, null);
        $basket->setStatus('new');
        $basket->setDiscountCode('SOLDES')->setDiscountKind('percentage')->setDiscountAmount(500);

        try {
            $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());
            $this->fail('An order carrying a code that no longer applies is refused');
        } catch (BasketNotOrderableException) {
        }

        $this->assertNull($basket->getDiscountCode(), 'The code goes, or the same refusal comes back forever');
        $this->assertSame(0, $basket->getDiscountAmount());
        $this->assertSame('new', $basket->getStatus(), 'Nothing was frozen: the basket is the one the customer left');
    }

    // The slug arrives off a form: one naming a provider the shop does not offer - keys cleared since the page was drawn, or simply typed in - would open a checkout the shop cannot be paid through
    public function testAGatewayNamedButNotOfferedFallsBackOnTheShopsOwn(): void
    {
        $basket = $this->basket(2500, $this->payment(2500, finished: false));
        $basket->setStatus('new');

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
        $payment = $this->payment(2500, finished: false);
        $basket = $this->basket(2500, $payment);
        $basket->setStatus('new');
        $basket->setTotal(9900);

        $this->service($basket, $this->returnAwareGateway(null), $this->sessionNaming(42))->validate(new Request());

        $this->assertSame($payment, $basket->getPayment());
        $this->assertSame(9900, $payment->getAmount());
        $this->assertSame('cs_1', $payment->getGatewayReference());
    }

    // ------------------------------------------------------------------ setup

    private function sessionNaming(int $id): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', $id);

        return $session;
    }

    private function basket(int $total, ?Payment $payment): Basket
    {
        $basket = new Basket();
        $basket->setStatus('validated');
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

    private function service(?Basket $basket = null, ?object $gateway = null, ?Session $session = null, ?InvoiceService $invoiceService = null, bool $testMode = false): BasketService
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('find')->willReturn($basket);
        $basketRepository->method('claimPaid')->willReturnCallback(function (Basket $claimed): bool {
            // The database moves the row, or answers that somebody else already did
            if ($this->claimAnswers) {
                $claimed->setStatus('paid');
            }

            return $this->claimAnswers;
        });

        // Each provider's own delivery effects, recorded rather than run
        $provider = $this->createStub(\c975L\PaymentBundle\Contract\BasketItemProviderInterface::class);
        $provider->method('onBasketValidated')->willReturn(['contributor' => 'Camille']);
        $provider->method('onBasketPaid')->willReturnCallback(function (Basket $basket, array $items, array $checkoutData): void {
            $this->delivered[] = 'product';
            $this->handedBack = $checkoutData;
        });
        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);

        $gatewayRegistry = $this->createStub(PaymentGatewayRegistry::class);
        $gatewayRegistry->method('getActiveOrNull')->willReturn($gateway);
        $gatewayRegistry->method('getOffered')->willReturn(null !== $gateway ? [$gateway->getSlug() => $gateway] : []);
        $gatewayRegistry->method('has')->willReturn(null !== $gateway);
        if (null !== $gateway) {
            $gatewayRegistry->method('getActive')->willReturn($gateway);
            $gatewayRegistry->method('get')->willReturn($gateway);
        }

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(fn (object $message): Envelope => $this->dispatched = new Envelope($message));

        $requestStack = new RequestStack();
        if (null !== $session) {
            $request = new Request();
            $request->setSession($session);
            $requestStack->push($request);
        }

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        return new BasketService(
            $basketRepository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            $requestStack,
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $messageBus,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $gatewayRegistry,
            $this->paymentTestMode($testMode),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($itemProviderRegistry),
            $invoiceService ?? $this->createStub(InvoiceService::class),
        );
    }
}

// A gateway that both charges and reads the customer's return, which is what a stub has to implement for BasketService to ask it
abstract class TestReturnAwareGateway implements \c975L\PaymentBundle\Contract\PaymentGatewayInterface, ExpirableGatewayInterface, ReturnAwareGatewayInterface
{
}
