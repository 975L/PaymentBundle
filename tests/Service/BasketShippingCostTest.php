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
use c975L\PaymentBundle\Contract\WeighableBasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the basket charges for delivery, now that it is the grid and no longer a single rate (see ShippingRateResolver)
class BasketShippingCostTest extends TestCase
{
    /** @var array<int, array{country: ?string, weight: int}> */
    private array $asked = [];

    // The weight of every line that says it has one, summed and handed to the grid - a basket of three 400 g articles is a 1200 g parcel
    public function testTheWeightOfEveryLineIsSummedAndPricedByTheGrid(): void
    {
        $basket = $this->basket(['a' => 400, 'b' => 800], country: 'FR');

        $this->service($basket, 790)->updateTotals();

        $this->assertSame(['country' => 'FR', 'weight' => 1200], $this->asked[0]);
        $this->assertSame(790, $basket->getShipping());
    }

    // A grid saying nothing charges nothing, which is what a shop that has written no zone does on purpose
    public function testAGridSayingNothingChargesNothing(): void
    {
        $basket = $this->basket(['a' => 400], country: 'FR');

        $this->service($basket, null)->updateTotals();

        $this->assertSame(0, $basket->getShipping());
    }

    // A provider that does not weigh its lines leaves the parcel where it stands rather than refusing to be counted
    public function testAProviderThatWeighsNothingLeavesTheParcelAtZero(): void
    {
        $basket = $this->basket(['a' => 400], country: 'FR');

        $this->service($basket, 490, weighs: false)->updateTotals();

        $this->assertSame(['country' => 'FR', 'weight' => 0], $this->asked[0]);
    }

    // Before the address is given, the page prices the parcel on the country the shop says it posts to by default - an estimate, and validate() charges the real one
    public function testTheDefaultCountryIsUsedUntilTheAddressIsGiven(): void
    {
        $basket = $this->basket(['a' => 400], country: null);

        $this->service($basket, 490)->updateTotals();

        $this->assertSame('BE', $this->asked[0]['country']);
    }

    // Nothing physical in the basket, nothing to post: the grid is not even asked
    public function testABasketWithNothingToPostIsChargedNoDelivery(): void
    {
        $basket = $this->basket(['a' => 400], country: 'FR');

        $this->service($basket, 790, physical: false)->updateTotals();

        $this->assertSame(0, $basket->getShipping());
    }

    /**
     * @param array<string, int> $weights
     */
    private function basket(array $weights, ?string $country): Basket
    {
        $items = [];
        foreach ($weights as $id => $weight) {
            $items['product'][$id] = ['quantity' => 1, 'total' => 1000, 'item' => ['weight' => $weight]];
        }

        return new Basket()
            ->setStatus('validated')
            ->setCurrency('EUR')
            ->setCountry($country)
            ->setItems($items)
        ;
    }

    private function service(Basket $basket, ?int $price, bool $weighs = true, bool $physical = true): BasketService
    {
        $provider = $this->provider($weighs, $physical);

        $registry = $this->createStub(BasketItemProviderRegistry::class);
        $registry->method('get')->willReturn($provider);
        $registry->method('has')->willReturn(true);

        $resolver = $this->createStub(ShippingRateResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (?string $country, int $weight) use ($price): ?int {
            $this->asked[] = ['country' => $country, 'weight' => $weight];

            return $price;
        });

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => match ($slug) {
            'shop-currency' => 'EUR',
            'shop-shipping-country' => 'BE',
            default => 0,
        });

        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findOneBy')->willReturn($basket);

        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', 1);
        $request = new Request();
        $request->setSession($session);

        $service = new BasketService(
            $repository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            new RequestStack([$request]),
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $registry,
            $this->createStub(PaymentGatewayRegistry::class),
            $this->createStub(PaymentTestModeInterface::class),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($registry),
            $this->createStub(InvoiceService::class),
            $resolver,
        );

        // The basket the service works on, which it would otherwise read off the session
        $property = new \ReflectionProperty(BasketService::class, 'basket');
        $property->setValue($service, $basket);

        return $service;
    }

    private function provider(bool $weighs, bool $physical): BasketItemProviderInterface
    {
        if (!$weighs) {
            $provider = $this->createStub(BasketItemProviderInterface::class);
            $provider->method('getContentFlags')->willReturn($physical ? Basket::CONTENT_FLAG_PHYSICAL : Basket::CONTENT_FLAG_DIGITAL);

            return $provider;
        }

        $provider = $this->createStub(WeighingProviderDouble::class);
        $provider->method('getContentFlags')->willReturn($physical ? Basket::CONTENT_FLAG_PHYSICAL : Basket::CONTENT_FLAG_DIGITAL);
        $provider->method('getWeight')->willReturnCallback(static fn (array $itemData): ?int => $itemData['item']['weight'] ?? null);

        return $provider;
    }
}

// A provider of both contracts, which is what a bundle selling something posted implements
abstract class WeighingProviderDouble implements BasketItemProviderInterface, WeighableBasketItemProviderInterface
{
}
