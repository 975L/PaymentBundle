<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
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
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// A company buying again finds its invoice details already filled in, taken from its last business order
class BasketBusinessPrefillTest extends TestCase
{
    // The last business order of the signed-in user fills the new basket's company, VAT number and address
    public function testANewBasketTakesTheLastBusinessOrderDetails(): void
    {
        $last = new Basket()->setCompany('ACME')->setVatNumber('FR12345678901')->setAddress('1 rue du Lac')->setZip('74000')->setCity('Annecy')->setCountry('FR');

        $basket = $this->service($this->createStub(UserInterface::class), $last)->create();

        $this->assertSame('ACME', $basket->getCompany());
        $this->assertSame('FR12345678901', $basket->getVatNumber());
        $this->assertSame('Annecy', $basket->getCity());
    }

    // A visitor, or a member who never ordered for a business, starts from an empty basket
    public function testNothingIsFilledWithoutABusinessOrder(): void
    {
        $this->assertNull($this->service(null, null)->create()->getCompany());
        $this->assertNull($this->service($this->createStub(UserInterface::class), null)->create()->getCompany());
    }

    private function service(?UserInterface $user, ?Basket $lastBusiness): BasketService
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        $basketRepository->method('findLastBusinessByUser')->willReturn($lastBusiness);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        if (null !== $user) {
            $token = $this->createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        }

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('EUR');

        return new BasketService(
            $basketRepository,
            $configService,
            $this->createStub(EntityManagerInterface::class),
            new RequestStack([$request]),
            $this->createStub(PaymentFormFactoryInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $tokenStorage,
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
