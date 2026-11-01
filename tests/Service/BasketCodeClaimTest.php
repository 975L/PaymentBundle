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
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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
 * A code is spent once: the amount is taken at validate() by the database, given back when the order stops being one, and an order the code did not cover is held rather than delivered.
 *
 * The repositories are played by fakes holding the same conditions as their SQL (see GiftCardRepositoryTest for the statements themselves), so two baskets can race for one card here.
 */
class BasketCodeClaimTest extends TestCase
{
    private GiftCard $card;

    private Discount $discount;

    /** @var array<int, bool> */
    private array $claimedBaskets = [];

    /** @var list<string> */
    private array $delivered = [];

    /** @var list<string> */
    private array $critical = [];

    private ?\Throwable $deliveryFails = null;

    private bool $checkoutFails = false;

    private bool $claimRefused = false;

    private ?Basket $current = null;

    protected function setUp(): void
    {
        $this->card = new GiftCard()->setCode('AAAABBBBCCCCDDDD')->setInitialAmount(3000)->setBalance(3000)->setCurrency('EUR');
        $this->discount = new Discount()->setCode('NOEL')->setKind(Discount::KIND_AMOUNT)->setValue(500)->setCurrency('EUR')->setMaxUses(1);
    }

    // The attack the audit names: both baskets priced while the card held 30 €, the second one is refused before it pays anything
    public function testTwoBasketsSharingOneCardCannotBothTakeIt(): void
    {
        $first = $this->basketWithCard(1, 3000);
        $second = $this->basketWithCard(2, 3000);

        $this->service($first)->validate($this->request());
        $this->assertSame(0, $this->card->getBalance());

        try {
            $this->service($second)->validate($this->request());
            $this->fail('The card held nothing left for the second basket');
        } catch (BasketNotOrderableException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame(BasketStatus::New, $second->getStatus());
        $this->assertSame(0, $second->getDiscountAmount());
        $this->assertSame(3000, $second->getPayable());
        $this->assertSame(0, $this->card->getBalance(), 'Nothing is taken twice');
    }

    // The race itself: both baskets read a balance that held, and the database is what refuses the one that comes second
    public function testAClaimTheDatabaseRefusesSendsTheCustomerBackToTheTruePrice(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $this->claimRefused = true;

        try {
            $this->service($basket)->validate($this->request());
            $this->fail('The database refused the amount');
        } catch (BasketNotOrderableException $exception) {
            $this->assertSame('error.code_changed', $exception->getMessage());
        }

        $this->assertSame(BasketStatus::New, $basket->getStatus());
        $this->assertNull($basket->getDiscountCode());
        $this->assertFalse($this->claimedBaskets[1], 'The marker goes back with the refusal');
    }

    // The quota, the same way: a code with one use left is refused to the second validation and not only counted at the second payment
    public function testAPromotionalCodeWithOneUseLeftServesOneOrder(): void
    {
        $first = $this->basketWithDiscount(1);
        $second = $this->basketWithDiscount(2);

        $this->service($first)->validate($this->request());
        $this->assertSame(1, $this->discount->getUsedCount());

        $this->expectException(BasketNotOrderableException::class);
        $this->service($second)->validate($this->request());
    }

    // The day a checkout is given up on counts from its validation, however old the basket was: the nightly pass must not let a claim go minutes after it was taken
    public function testValidatingDatesTheOrder(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $basket->setModification(new \DateTime('-3 days'));

        $this->service($basket)->validate($this->request());

        $this->assertGreaterThan(new \DateTime('-1 minute'), $basket->getModification());
    }

    // Validating twice in a row is one order: the claim of the first pass is given back before the second takes it, and the card ends up debited once
    public function testValidatingTheSameBasketAgainClaimsOnce(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $service = $this->service($basket);

        $service->validate($this->request());
        $service->validate($this->request());

        $this->assertSame(0, $this->card->getBalance());
        $this->assertTrue($basket->isDiscountClaimed());
    }

    // Reopened by an edit, the order is no longer one: what it held goes back on the card
    public function testAReopenedBasketGivesTheAmountBack(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $service = $this->service($basket);

        $service->validate($this->request());
        $service->removeCode();

        $this->assertSame(3000, $this->card->getBalance());
        $this->assertFalse($basket->isDiscountClaimed());
        $this->assertSame(BasketStatus::New, $basket->getStatus());
    }

    // The basket thrown away from the page: its claim leaves with it
    public function testADeletedValidatedBasketGivesTheAmountBack(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $service = $this->service($basket);

        $service->validate($this->request());
        $service->delete();

        $this->assertSame(3000, $this->card->getBalance());
    }

    // A checkout the provider refuses to open is no order: the amount taken a moment earlier does not stay taken
    public function testACheckoutThatCannotBeOpenedGivesTheAmountBack(): void
    {
        $basket = $this->basketWithCard(1, 3000, total: 5000);
        $this->checkoutFails = true;

        try {
            $this->service($basket)->validate($this->request());
            $this->fail('The provider refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stripe is down', $exception->getMessage());
        }

        $this->assertSame(3000, $this->card->getBalance());
        $this->assertFalse($basket->isDiscountClaimed());
    }

    // Claimed at validate(), so paid() takes nothing a second time
    public function testPaidDoesNotTakeTheAmountAgain(): void
    {
        $basket = $this->basketWithCard(1, 3000);
        $service = $this->service($basket);

        $service->validate($this->request());
        $service->paid($basket);

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertSame(0, $this->card->getBalance());
        $this->assertSame(['product'], $this->delivered);
    }

    // An order validated before this claim existed, or whose claim was let go: spent at paid() as it always was
    public function testPaidClaimsAnOrderThatHoldsNoClaimYet(): void
    {
        $basket = $this->chargedBasketWithCard(1000);

        $this->service($basket)->paid($basket);

        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertSame(2000, $this->card->getBalance());
    }

    // The customer paid the rest by card and the code cannot cover its share: nothing is delivered for free, the order is neither lost nor reopened, and the shop is told
    public function testAnOrderTheCodeNoLongerCoversIsHeldAndFlagged(): void
    {
        $this->card->setBalance(100);
        $basket = $this->chargedBasketWithCard(1000);
        $service = $this->service($basket);

        $service->paid($basket);

        $this->assertSame([], $this->delivered);
        $this->assertSame(BasketStatus::Validated, $basket->getStatus(), 'Moved back, the claim having gone round the unit of work');
        $this->assertTrue($basket->getPayment()->isFinished(), 'The money is recorded: it stands on the dashboard as charged and undelivered');
        $this->assertTrue($basket->isOrder());
        $this->assertCount(1, $this->critical);
        $this->assertSame(100, $this->card->getBalance());
    }

    // The way out once the shop has put the card right: the same order, delivered once
    public function testAHeldOrderIsDeliveredOnceTheCodeCoversIt(): void
    {
        $this->card->setBalance(100);
        $basket = $this->chargedBasketWithCard(1000);
        $service = $this->service($basket);
        $service->paid($basket);

        $this->card->setBalance(1000);
        $service->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
        $this->assertSame(0, $this->card->getBalance());
    }

    // A held order is money already taken: no edit reopens it, nor does adding to it, and the page starts a new basket
    public function testAHeldOrderIsNotReopened(): void
    {
        $this->card->setBalance(100);
        $basket = $this->chargedBasketWithCard(1000);
        $service = $this->service($basket);
        $service->paid($basket);

        $service->removeCode();

        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
        $this->assertSame('AAAABBBBCCCCDDDD', $basket->getDiscountCode());
    }

    // ------------------------------------------------------ a failing delivery

    // A provider throwing halfway: the order goes back to where a retry finds it, and the error is not swallowed so that the provider's webhook replays
    public function testADeliveryThatThrowsLeavesTheOrderToARetry(): void
    {
        $basket = $this->chargedBasketWithCard(1000);
        $this->deliveryFails = new \RuntimeException('The mailer is down');

        try {
            $this->service($basket)->paid($basket);
            $this->fail('The provider threw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The mailer is down', $exception->getMessage());
        }

        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
        $this->assertCount(1, $this->critical);
    }

    // The retry the webhook replay, the customer's reload or the back-office button make
    public function testARetryDeliversOnceTheProviderStopsThrowing(): void
    {
        $basket = $this->chargedBasketWithCard(1000);
        $this->deliveryFails = new \RuntimeException('The mailer is down');
        $service = $this->service($basket);

        // Answered 500 to the provider, the replay coming below
        try {
            $service->paid($basket);
            $this->fail('The provider threw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The mailer is down', $exception->getMessage());
        }

        $this->deliveryFails = null;
        $service->paid($basket);

        $this->assertSame(['product'], $this->delivered);
        $this->assertSame(BasketStatus::Paid, $basket->getStatus());
    }

    // The customer standing on the return page gets their order as it stands rather than an error page, the webhook retrying behind them
    public function testACustomerReturningOnAFailedDeliverySeesNoError(): void
    {
        $basket = $this->chargedBasketWithCard(1000);
        $this->deliveryFails = new \RuntimeException('The mailer is down');

        $this->service($basket)->confirmReturn($basket, $this->request());

        $this->assertSame(BasketStatus::Validated, $basket->getStatus());
    }

    // ------------------------------------------------------------------ setup

    private function request(): Request
    {
        return new Request();
    }

    private function basketWithCard(int $id, int $amount, int $total = 3000): Basket
    {
        return $this->basketOf($id, $total)
            ->setDiscountCode('AAAABBBBCCCCDDDD')
            ->setDiscountKind(Basket::CODE_KIND_GIFT_CARD)
            ->setDiscountAmount($amount);
    }

    private function basketWithDiscount(int $id): Basket
    {
        return $this->basketOf($id, 3000)
            ->setDiscountCode('NOEL')
            ->setDiscountKind(Basket::CODE_KIND_DISCOUNT)
            ->setDiscountAmount(500);
    }

    // An order whose rest the provider has confirmed, and which holds no claim: validated before this design, or let go by the nightly pass
    private function chargedBasketWithCard(int $amount): Basket
    {
        $basket = $this->basketWithCard(1, $amount, total: 5000);
        $basket->setStatus(BasketStatus::Validated);
        $basket->setNumber('202611-AB-12345');
        $basket->setPayment(new Payment()->setGateway('stripe')->setAmount(4000)->setCurrency('EUR')->setFinished(true));

        return $basket;
    }

    private function basketOf(int $id, int $total): Basket
    {
        $basket = new Basket();
        $basket->setStatus(BasketStatus::New);
        $basket->setTotal($total);
        $basket->setShipping(0);
        $basket->setQuantity(1);
        $basket->setCurrency('EUR');
        $basket->setEmail('marie@example.org');
        $basket->setItems(['product' => [1 => [
            'quantity' => 1,
            'total' => $total,
            'parent' => ['title' => 'Un livre'],
            'item' => ['title' => 'Broché', 'price' => $total],
        ]]]);

        new \ReflectionProperty(Basket::class, 'id')->setValue($basket, $id);

        return $basket;
    }

    // The session naming that basket, the repositories acting as their SQL does on the one card and the one code of the test
    private function service(Basket $basket): BasketService
    {
        $this->current = $basket;

        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', $basket->getId());
        $request = new Request();
        $request->setSession($session);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $testMode = $this->createStub(PaymentTestModeInterface::class);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('getContentFlags')->willReturn(Basket::CONTENT_FLAG_SERVICE);
        $provider->method('onBasketValidated')->willReturn([]);
        $provider->method('onBasketPaid')->willReturnCallback(function (): void {
            if (null !== $this->deliveryFails) {
                throw $this->deliveryFails;
            }
            $this->delivered[] = 'product';
        });

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);

        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('createCheckout')->willReturnCallback(function (): CheckoutSession {
            if ($this->checkoutFails) {
                throw new \RuntimeException('Stripe is down');
            }

            return new CheckoutSession('https://checkout.stripe.com/c/pay/cs_1', 'cs_1');
        });

        $gatewayRegistry = $this->createStub(PaymentGatewayRegistry::class);
        $gatewayRegistry->method('getOffered')->willReturn(['stripe' => $gateway]);
        $gatewayRegistry->method('getActive')->willReturn($gateway);
        $gatewayRegistry->method('has')->willReturn(false);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $logger = new class ($this->critical) extends AbstractLogger {
            /** @param list<string> $critical */
            public function __construct(private array &$critical)
            {
            }

            public function log($level, \Stringable | string $message, array $context = []): void
            {
                if ('critical' === $level) {
                    $this->critical[] = (string) $message;
                }
            }
        };

        $basketRepository = $this->basketRepository();

        return new BasketService(
            $basketRepository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            new RequestStack([$request]),
            $this->createStub(PaymentFormFactoryInterface::class),
            $translator,
            $messageBus,
            $this->createStub(UrlGeneratorInterface::class),
            $logger,
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $gatewayRegistry,
            $testMode,
            new BasketCodeService($this->discountRepository(), $this->giftCardRepository(), $translator, $testMode, $basketRepository),
            new VatCalculator($itemProviderRegistry),
            $this->createStub(InvoiceService::class),
            $this->createStub(ShippingRateResolverInterface::class),
            $this->createStub(BasketRefundService::class),
        );
    }

    // The conditions of BasketRepository's statements: a marker taken once per basket, and a status moved once
    private function basketRepository(): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturnCallback(fn (): ?Basket => $this->current);
        $repository->method('claimPaid')->willReturnCallback(static function (Basket $basket): bool {
            if (BasketStatus::Validated !== $basket->getStatus()) {
                return false;
            }
            $basket->setStatus(BasketStatus::Paid);

            return true;
        });
        $repository->method('releasePaid')->willReturnCallback(static function (Basket $basket): void {
            $basket->setStatus(BasketStatus::Validated);
        });
        $repository->method('markCodeClaimed')->willReturnCallback(function (Basket $basket): bool {
            if ($this->claimedBaskets[$basket->getId()] ?? false) {
                return false;
            }

            return $this->claimedBaskets[$basket->getId()] = true;
        });
        $repository->method('markCodeReleased')->willReturnCallback(function (Basket $basket): bool {
            if (!($this->claimedBaskets[$basket->getId()] ?? false)) {
                return false;
            }
            $this->claimedBaskets[$basket->getId()] = false;

            return true;
        });

        return $repository;
    }

    // The conditions of claimAmount(): the balance itself decides
    private function giftCardRepository(): GiftCardRepository
    {
        $repository = $this->createStub(GiftCardRepository::class);
        $repository->method('findOneByCode')->willReturnCallback(fn (string $code): ?GiftCard => $code === $this->card->getCode() ? $this->card : null);
        $repository->method('claimAmount')->willReturnCallback(function (GiftCard $card, int $amount): bool {
            if ($this->claimRefused || $card->getBalance() < $amount) {
                return false;
            }
            $card->setBalance($card->getBalance() - $amount);

            return true;
        });
        $repository->method('creditAmount')->willReturnCallback(static function (GiftCard $card, int $amount): void {
            $card->setBalance($card->getBalance() + $amount);
        });

        return $repository;
    }

    // The conditions of claimUse(): the quota itself decides
    private function discountRepository(): DiscountRepository
    {
        $repository = $this->createStub(DiscountRepository::class);
        $repository->method('findOneByCode')->willReturnCallback(fn (string $code): ?Discount => $code === $this->discount->getCode() ? $this->discount : null);
        $repository->method('claimUse')->willReturnCallback(static function (Discount $discount): bool {
            if ($discount->getMaxUses() > 0 && $discount->getUsedCount() >= $discount->getMaxUses()) {
                return false;
            }
            $discount->setUsedCount($discount->getUsedCount() + 1);

            return true;
        });
        $repository->method('releaseUse')->willReturnCallback(static function (Discount $discount): void {
            $discount->setUsedCount($discount->getUsedCount() - 1);
        });

        return $repository;
    }
}
