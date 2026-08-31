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

/**
 * What clicking "add" a second time does to a line already in the basket.
 *
 * The three answers are not the same: an article is counted up, a file is not - the customer downloads the one copy
 * they bought however many times they click - and a quantity brought back to zero takes the line off the basket
 * rather than leaving it at nought.
 */
class BasketAdditionTest extends TestCase
{
    // The ordinary case: the same article asked for again is counted, not laid a second time beside itself
    public function testAskingForTheSameArticleAgainCountsItUp(): void
    {
        $basket = $this->basketHolding(1);
        $service = $this->service($basket, new AddableItem(7, 1000));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => 2]));

        $this->assertSame(3, $basket->getItems()['product'][7]['quantity']);
    }

    // The basket page's "-" button walks the quantity down, and the last press takes the line off rather than showing an article ordered nought times
    public function testAQuantityBroughtBackToZeroTakesTheLineOff(): void
    {
        $basket = $this->basketHolding(2);
        $service = $this->service($basket, new AddableItem(7, 1000));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => -2]));

        $this->assertArrayNotHasKey(7, $basket->getItems()['product']);
    }

    // Below zero is still zero: a quantity larger than what the line holds must not leave it owing articles
    public function testAQuantityBelowZeroTakesTheLineOffAsWell(): void
    {
        $basket = $this->basketHolding(1);
        $service = $this->service($basket, new AddableItem(7, 1000));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => -5]));

        $this->assertArrayNotHasKey(7, $basket->getItems()['product']);
    }

    // A file is bought once and downloaded as often as wanted: counting it up would charge twice for the same download
    public function testADigitalArticleIsNeverCountedUp(): void
    {
        $basket = $this->basketHolding(1);
        $service = $this->service($basket, new DownloadableItem(7, 1000));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => 3]));

        $this->assertSame(1, $basket->getItems()['product'][7]['quantity']);
    }

    // A file the visitor no longer wants still leaves the basket, the quantity rule above bearing on counting up and not on removal
    public function testADigitalArticleStillLeavesOnAZeroQuantity(): void
    {
        $basket = $this->basketHolding(1);
        $service = $this->service($basket, new DownloadableItem(7, 1000));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 7, 'quantity' => -1]));

        $this->assertArrayNotHasKey(7, $basket->getItems()['product']);
    }

    // An article the basket doesn't hold opens its own line, whatever the others hold
    public function testAnArticleTheBasketDoesNotHoldOpensItsOwnLine(): void
    {
        $basket = $this->basketHolding(1);
        $service = $this->service($basket, new AddableItem(9, 2500));

        $service->addItem($this->jsonRequest(['type' => 'product', 'id' => 9, 'quantity' => 1]));

        $this->assertArrayHasKey(9, $basket->getItems()['product']);
        $this->assertSame(1, $basket->getItems()['product'][9]['quantity']);
    }

    private function basketHolding(int $quantity): Basket
    {
        $basket = new Basket();
        $basket->setStatus('new');
        $basket->setCurrency('EUR');
        $basket->setTotal(1000 * $quantity);
        $basket->setShipping(0);
        $basket->setQuantity($quantity);
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setItems(['product' => [7 => [
            'quantity' => $quantity,
            'total' => 1000 * $quantity,
            'totalVat' => 0,
            'type' => 'product',
            'item' => ['id' => 7, 'title' => 'Article', 'price' => 1000, 'vat' => 0.0],
            'parent' => [],
        ]]]);

        return $basket;
    }

    private function jsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function service(Basket $basket, object $item): BasketService
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('find')->willReturn($basket);

        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('findItem')->willReturn($item);
        $provider->method('validateAddition')->willReturn(null);
        $provider->method('getContentFlags')->willReturn(0);
        $provider->method('toBasketData')->willReturnCallback(fn (object $found, int $quantity): array => [
            'item' => ['id' => $found->getId(), 'title' => 'Article', 'price' => $found->getPrice(), 'vat' => 0.0],
            'parent' => [],
            'type' => 'product',
            'quantity' => $quantity,
            'totalVat' => 0,
            'total' => $found->getPrice() * $quantity,
        ]);

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);
        $itemProviderRegistry->method('has')->willReturn(true);

        $session = new Session(new MockArraySessionStorage());
        $session->set('basket', 1);
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        return new BasketService(
            $basketRepository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            $requestStack,
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $this->createStub(PaymentGatewayRegistry::class),
            $this->createStub(PaymentTestModeInterface::class),
            new BasketCodeService($this->createStub(DiscountRepository::class), $this->createStub(GiftCardRepository::class), $this->createStub(TranslatorInterface::class), $this->createStub(PaymentTestModeInterface::class)),
            new VatCalculator($itemProviderRegistry),
            $this->createStub(InvoiceService::class),
            $this->createStub(ShippingRateResolverInterface::class),
        );
    }
}

// A catalogue entry as the addition reads it: an id and a price, which is all BasketService asks an ordinary article for
class AddableItem
{
    public function __construct(
        private readonly int | string $id,
        private readonly int $price,
    ) {
    }

    public function getId(): int | string
    {
        return $this->id;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}

// The same, carrying a file: what tells BasketService the article is downloaded rather than shipped
class DownloadableItem extends AddableItem
{
    public function getFile(): object
    {
        return new DownloadableFile();
    }
}

// Vich hands back an object naming the stored file, and the name is the only thing read of it here
class DownloadableFile
{
    public function getName(): string
    {
        return 'manual.pdf';
    }
}
