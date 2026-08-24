<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Controller\BasketController;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\PaymentBundle\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Where the visitor is sent when they validate their basket.
 *
 * The three ways out are pinned here: the provider's checkout when the basket can be ordered and paid for,
 * and the basket page carrying a flash when it cannot - a payment key missing, or a line that ran out while
 * the basket sat there. In both refusals the basket is left untouched, so the visitor comes back to it.
 */
class BasketControllerTest extends TestCase
{
    private Session $session;

    /** @var array<string, mixed> */
    private array $criteria = [];

    private bool $flushed = false;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
    }

    // A basket the session no longer names sends the visitor back to the basket page rather than answering a 500
    public function testAVisitorWithoutABasketIsSentBackToTheBasketPage(): void
    {
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(null);

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
    }

    // The one way through: validate() answers the url to pay at, and the visitor is sent to it without anything else being rendered
    public function testAValidatedBasketSendsTheVisitorToTheProvider(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willReturn('https://checkout.example/session-1');

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://checkout.example/session-1', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
    }

    // A shop whose payment keys are missing takes no order rather than answering a 500, and says so once
    public function testAShopWithoutAPaymentKeyRefusesWithAFlash(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willThrowException(new PaymentUnavailableException('no key'));

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(['flash.payment_unavailable'], $this->session->getFlashBag()->get('danger'));
    }

    // The provider's own message is passed on as it is: only the bundle owning the item can say whether it ran out, was withdrawn or was taken offline
    public function testAnItemNoLongerOrderableRefusesWithTheProvidersOwnMessage(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willThrowException(new BasketNotOrderableException('Only 2 left of "Mug"'));

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
        $this->assertSame(['Only 2 left of "Mug"'], $this->session->getFlashBag()->get('danger'));
    }

    // The buyer lands here with their files, so a download email that never arrived leaves nobody without what they paid for
    public function testAPaidBasketIsHandedItsDownloadsOnThePage(): void
    {
        $downloads = [['title' => 'A book', 'url' => '/shop/download/abcd', 'size' => 1024]];
        $basket = new Basket()->setStatus('paid');

        $parameters = $this->renderedParameters($basket, $downloads);

        $this->assertSame($downloads, $parameters['downloads']);
    }

    // A basket still being paid is never told about its files, whatever a provider would answer
    public function testABasketNotYetPaidIsHandedNoDownload(): void
    {
        $basket = new Basket()->setStatus('waiting');

        $parameters = $this->renderedParameters($basket, [['title' => 'A book', 'url' => '/shop/download/abcd', 'size' => 1024]]);

        $this->assertSame([], $parameters['downloads']);
    }

    /**
     * @param list<array{title: string, url: string, size: ?int}> $downloads what every provider would answer for that basket
     *
     * @return array<string, mixed> what the paid page is rendered with
     */
    private function renderedParameters(Basket $basket, array $downloads): array
    {
        $downloadRegistry = $this->createStub(BasketDownloadRegistry::class);
        $downloadRegistry->method('getDownloads')->willReturn($downloads);

        $parameters = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $context) use (&$parameters): string {
            $parameters = $context;

            return '';
        });

        $controller = $this->controller($this->createStub(BasketServiceInterface::class), $downloadRegistry, $twig);
        $controller->paid($basket, new Request());

        return $parameters;
    }

    // A basket, its coordinates form filled in and submitted
    private function basketService(): BasketServiceInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(new Basket());
        $basketService->method('createForm')->willReturn($form);

        return $basketService;
    }

    // The address a payment link travels by, short enough to leave room for a sentence in a text message
    public function testTheShortAddressRedirectsToThePayersOwnPage(): void
    {
        $basket = new Basket()
            ->setNumber('202608-AB-12345')
            ->setShareToken('aaaabbbbccccdddd')
        ;

        $response = $this->controller($this->createStub(BasketServiceInterface::class))
            ->shortPay('aaaabbbbccccdddd', $this->basketRepository($basket))
        ;

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/basket_shared_pay', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // An address dictated over the telephone and retyped comes back capitalised, and must open the order it names all the same - the token is stored in lower case, and looked up in lower case whatever the site's collation says
    public function testAnAddressRetypedInCapitalsOpensTheSameOrder(): void
    {
        $basket = new Basket()
            ->setNumber('202608-AB-12345')
            ->setShareToken('aaaabbbbccccdddd')
        ;
        $repository = $this->basketRepository($basket);

        $response = $this->controller($this->createStub(BasketServiceInterface::class))
            ->shortPay('AAAABBBBCCCCDDDD', $repository)
        ;

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['shareToken' => 'aaaabbbbccccdddd'], $this->criteria);
    }

    // An order nobody was asked to settle has no payer's page to send anybody to, and a token guessed at opens nothing
    public function testAnOrderThatWasNeverSharedIsNotFoundAtTheShortAddress(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->createStub(BasketServiceInterface::class))
            ->shortPay('aaaabbbbccccdddd', $this->basketRepository(new Basket()))
        ;
    }

    /**
     * The way out carried by every reminder of an unpaid order.
     *
     * One click and nothing else: the link is in the recipient's own e-mail and asking to hear no more is what
     * they came for, where a second click is what makes people mark the message as spam instead.
     */
    public function testTheUnsubscribeLinkStopsTheRemindersInOneClick(): void
    {
        $basket = new Basket()
            ->setNumber('202608-AB-12345')
            ->setShareToken('aaaabbbbccccdddd')
        ;

        $response = $this->controller($this->createStub(BasketServiceInterface::class), twig: $this->createStub(Environment::class))
            ->unsubscribeReminder($basket, $this->entityManager())
        ;

        $this->assertNotNull($basket->getReminderOptOutAt());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($this->flushed);
    }

    // The address is guarded by the order number and the share token together, so an order carrying no token is one nobody can be unsubscribed from
    public function testAnOrderThatWasNeverSharedHasNoUnsubscribeAddress(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->createStub(BasketServiceInterface::class))
            ->unsubscribeReminder(new Basket(), $this->entityManager())
        ;
    }

    // Says whether the opposition was written rather than only set on the object in memory
    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->flushed = true;
        });

        return $entityManager;
    }

    // The order the short address names, and what it was looked up with
    private function basketRepository(?Basket $basket): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria) use ($basket): ?Basket {
            $this->criteria = $criteria;

            return $basket;
        });

        return $repository;
    }

    private function controller(BasketServiceInterface $basketService, ?BasketDownloadRegistry $downloadRegistry = null, ?Environment $twig = null): BasketController
    {
        // The translator answers the key itself, so a flash is asserted on the key rather than on a wording
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new BasketController(
            $this->createStub(ConfigServiceInterface::class),
            $basketService,
            $this->createStub(BasketRecommendationRegistry::class),
            $downloadRegistry ?? $this->createStub(BasketDownloadRegistry::class),
            $translator,
            $this->createStub(InvoiceService::class),
        );

        $controller->setContainer($this->container($twig));

        return $controller;
    }

    // What AbstractController reaches for on these paths: the router for redirectToRoute(), the request stack for addFlash(), and twig for render()
    private function container(?Environment $twig = null): ContainerInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name): string => 'basket_display' === $name ? '/shop/basket/display' : '/' . $name
        );

        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack([$request]);

        $services = ['router' => $router, 'request_stack' => $requestStack, 'twig' => $twig];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }
}
