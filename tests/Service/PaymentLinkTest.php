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
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentLinkItem;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Provider\PaymentLinkItemProvider;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// An order written for something the catalogue does not sell, handed over as a link (see BasketService::createPaymentLink())
class PaymentLinkTest extends TestCase
{
    private ?Basket $persisted = null;

    private ?CheckoutRequest $checkoutRequest = null;

    /** @var array{string, array<string, mixed>}|null */
    private ?array $generated = null;

    // Frozen exactly as a shared order is: the checkout is opened by whoever follows the link, not here
    public function testTheOrderIsWrittenValidatedAndWaitingForItsMoney(): void
    {
        $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $this->assertNotNull($this->persisted);
        $this->assertSame('validated', $this->persisted->getStatus());
        $this->assertSame(25000, $this->persisted->getTotal());
        $this->assertSame(25000, $this->persisted->getPayable());
        $this->assertSame(1, $this->persisted->getQuantity());
        $this->assertSame('marie@example.org', $this->persisted->getEmail());
        $this->assertNull($this->persisted->getPayment());
    }

    /**
     * The mode the shop was charging in the day the link was written, stamped on the order.
     *
     * A link minted during a rehearsal is settled with the provider's test keys, so it is billed no invoice
     * number - see InvoiceService::assign(), which reads this very flag.
     */
    public function testTheLinkRemembersTheModeItWasWrittenIn(): void
    {
        $this->service()->createPaymentLink('Acompte', 5000, 'marie@example.org');
        $this->assertFalse($this->persisted?->isTestMode());

        $this->service(testMode: true)->createPaymentLink('Acompte', 5000, 'marie@example.org');
        $this->assertTrue($this->persisted?->isTestMode());
    }

    // A service is carried by nothing, whatever the shop charges to deliver its goods
    public function testNoDeliveryIsChargedForALink(): void
    {
        $this->service()->createPaymentLink('Réparation', 8000, 'marie@example.org');

        $this->assertSame(0, $this->persisted->getShipping());
        $this->assertSame(Basket::CONTENT_FLAG_SERVICE, $this->persisted->getContentFlags());
        $this->assertSame(0, $this->persisted->getContentFlags() & Basket::CONTENT_FLAG_PHYSICAL);
    }

    // The line is built by the provider and not by the service, so every page reading a basket reads this one the same way
    public function testTheOrderHoldsOneLineOfItsOwnKind(): void
    {
        $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org', 'Solde à la livraison');

        $items = $this->persisted->getItems();
        $this->assertSame([PaymentLinkItemProvider::KIND], array_keys($items));
        $this->assertSame('Acompte chantier', $items[PaymentLinkItemProvider::KIND][PaymentLinkItem::ID]['item']['title']);
        $this->assertSame('Solde à la livraison', $items[PaymentLinkItemProvider::KIND][PaymentLinkItem::ID]['item']['description']);
    }

    // The address handed over is the payer's, which shows what is bought and nothing of who it is for - the security token opens the delivery page and stays the shop's own
    public function testThePayerIsSentToAnAddressOfTheirOwn(): void
    {
        $url = $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $this->assertSame('https://example.org/pay', $url);
        $this->assertNotNull($this->persisted->getShareToken());
        $this->assertNotSame($this->persisted->getSecurityToken(), $this->persisted->getShareToken());
        $this->assertTrue($this->persisted->isShared());
    }

    // A payment link is sent by text message as often as by e-mail, where the long address spends half of the 160 characters one holds - and the number beside the token names nothing the token does not
    public function testTheAddressHandedOverIsTheShortOne(): void
    {
        $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $this->assertSame('basket_short_pay', $this->generated[0]);
        $this->assertSame(['shareToken' => $this->persisted->getShareToken()], $this->generated[1]);
    }

    // Never filed under the admin who wrote it: an order attached to them would show up in their own order history
    public function testTheLinkIsFiledUnderNobody(): void
    {
        $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $this->assertNull($this->persisted->getUser());
    }

    // The reminders are for the customers who walked out of a checkout, not for a link the shop wrote: the service flag is what the repository reads a link by, and it is stamped here
    public function testALinkIsNeverEligibleForAReminder(): void
    {
        $this->service()->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $this->assertSame(Basket::CONTENT_FLAG_SERVICE, $this->persisted->getContentFlags());
        $this->assertSame(0, $this->persisted->getRemindersSent());
    }

    /**
     * What the provider prints on the payer's card statement.
     *
     * A catalogue line is named "Parent (item)"; one hanging under no catalogue entry is named by its label
     * alone, rather than by that same label wrapped in an empty pair of brackets.
     */
    public function testTheProviderIsGivenTheLabelAloneAsTheLineName(): void
    {
        $service = $this->service();
        $service->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');

        $service->payShared($this->persisted);

        $this->assertNotNull($this->checkoutRequest);
        $this->assertSame('Acompte chantier', $this->checkoutRequest->lines[0]['name']);
        $this->assertSame(25000, $this->checkoutRequest->lines[0]['amount']);
        $this->assertSame('marie@example.org', $this->checkoutRequest->email);
    }

    public function testAnAmountWorthNothingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->createPaymentLink('Acompte chantier', 0, 'marie@example.org');
    }

    // Asked before anything is written, as validate() asks it: a link nobody can open a checkout from is a link nobody can settle
    public function testNothingIsWrittenWhenNoGatewayCanTakeThePayment(): void
    {
        $this->expectException(PaymentUnavailableException::class);

        try {
            $this->service(withGateway: false)->createPaymentLink('Acompte chantier', 25000, 'marie@example.org');
        } finally {
            $this->assertNull($this->persisted);
        }
    }

    private function service(bool $withGateway = true, bool $testMode = false): BasketService
    {
        $gateway = null;
        if ($withGateway) {
            $gateway = $this->createStub(PaymentGatewayInterface::class);
            $gateway->method('isConfigured')->willReturn(true);
            $gateway->method('getSlug')->willReturn('stripe');
            $gateway->method('createCheckout')->willReturnCallback(function (CheckoutRequest $request): CheckoutSession {
                $this->checkoutRequest = $request;

                return new CheckoutSession('https://provider.example/checkout', 'cs_test_1');
            });
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Basket) {
                $this->persisted = $entity;
            }
        });

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => match ($slug) {
            'shop-currency' => 'EUR',
            'payment-link-vat-rate' => '20',
            default => 0,
        });

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn(new PaymentLinkItemProvider($configService));

        $gatewayRegistry = $this->createStub(PaymentGatewayRegistry::class);
        $gatewayRegistry->method('getActiveOrNull')->willReturn($gateway);
        $gatewayRegistry->method('getOffered')->willReturn(null !== $gateway ? [$gateway->getSlug() => $gateway] : []);
        if (null !== $gateway) {
            $gatewayRegistry->method('getActive')->willReturn($gateway);
        }

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $parameters = []): string {
            $this->generated = [$route, $parameters];

            return 'https://example.org/pay';
        });

        $requestStack = new RequestStack([new Request()]);

        return new BasketService(
            $this->createStub(BasketRepository::class),
            $configService,
            $entityManager,
            $requestStack,
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MessageBusInterface::class),
            $urlGenerator,
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $gatewayRegistry,
            $this->paymentTestMode($testMode),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($itemProviderRegistry),
            $this->createStub(InvoiceService::class),
        );
    }

    private function paymentTestMode(bool $enabled): PaymentTestModeInterface
    {
        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($enabled);

        return $testMode;
    }
}
