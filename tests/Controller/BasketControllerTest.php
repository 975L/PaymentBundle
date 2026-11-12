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
use c975L\ConfigBundle\Service\LocalizedRouteNegotiator;
use c975L\ConfigBundle\Service\SiteLocales;
use c975L\PaymentBundle\Controller\BasketController;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\UiBundle\Service\AgeVerifier;
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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\LocaleSwitcher;
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

    // The session still names an order, its customer having paid and never come back: the coordinates form is not drawn on it, the session lets go and the visitor starts over
    public function testAnOrderNamedByTheSessionIsNotCheckedOutAgain(): void
    {
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(new Basket()->setStatus(BasketStatus::Paid));
        $basketService->expects($this->once())->method('delete');
        $basketService->expects($this->never())->method('createForm');

        $response = $this->controller($basketService)->validate(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/shop/basket/display', $response->getTargetUrl());
    }

    // The address an old link or a third party can put in front of an admin: reaching it writes nothing, and the page asks
    public function testReachingTheShippingAddressShipsNothingAndAsks(): void
    {
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->never())->method('itemsShipped');

        $response = $this->controller($basketService, twig: $this->twigNaming(), csrfValid: true, granted: true)->itemsShipped(Request::create('/x'), '202611-AB-12345', 'product');

        $this->assertSame('@c975LPayment/basket/ship_confirm.html.twig', $response->getContent());
    }

    // A form posted from anywhere else carries no token of this session: asked again, and nothing goes out
    public function testAPostWithoutAValidTokenShipsNothing(): void
    {
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->never())->method('itemsShipped');

        $response = $this->controller($basketService, twig: $this->twigNaming(), csrfValid: false, granted: true)->itemsShipped(Request::create('/x', 'POST', ['_token' => 'forged']), '202611-AB-12345', 'product');

        $this->assertSame('@c975LPayment/basket/ship_confirm.html.twig', $response->getContent());
    }

    public function testAPostWithItsTokenShips(): void
    {
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->once())->method('itemsShipped')->with('202611-AB-12345', 'product')->willReturn(new Basket());

        $response = $this->controller($basketService, twig: $this->twigNaming(), csrfValid: true, granted: true)->itemsShipped(Request::create('/x', 'POST', ['_token' => 'good']), '202611-AB-12345', 'product');

        $this->assertSame('@c975LPayment/basket/shipped.html.twig', $response->getContent());
    }

    // Asked of the admin alone, whatever the method
    public function testShippingIsRefusedToAnybodyButAnAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->controller($this->createStub(BasketServiceInterface::class), twig: $this->twigNaming(), csrfValid: true, granted: false)->itemsShipped(Request::create('/x'), '202611-AB-12345', 'product');
    }

    // The route answers the landing page and the form, under the name the back-office links to
    public function testTheShippingRouteTakesTheLinkAndTheForm(): void
    {
        $route = new \ReflectionMethod(BasketController::class, 'itemsShipped')->getAttributes(Route::class)[0]->getArguments();

        $this->assertSame('items_shipped', $route['name']);
        $this->assertSame(['GET', 'POST'], $route['methods']);
    }

    // A visitor checking out in English comes back to the English basket, never to the writing language halfway through their own checkout
    public function testAVisitorReadingAnotherLanguageIsSentBackToTheBasketInThatLanguage(): void
    {
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn(null);

        $request = new Request();
        $request->attributes->set('_locale', 'en');

        $response = $this->controller($basketService)->validate($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/en/shop/basket/display', $response->getTargetUrl());
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

    // A basket holding what lands on an account sends an anonymous visitor to sign in, and back to this very page afterwards, rather than letting them type coordinates the checkout would then refuse
    public function testAnAnonymousVisitorIsAskedToSignInBeforeTheCoordinates(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/shop/basket/validate']);

        $response = $this->controller($this->basketService(), accountRequired: true)->validate($request);

        $this->assertSame('/app_login?_target_path=/shop/basket/validate', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame(['flash.sign_in_to_order'], $this->session->getFlashBag()->get('info'));
    }

    // Once signed in, the same basket goes through to the payment as any other
    public function testASignedInVisitorGoesThroughToThePayment(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willReturn('https://checkout.example/session-1');

        $response = $this->controller($basketService, accountRequired: true, signedIn: true)->validate(new Request());

        $this->assertSame('https://checkout.example/session-1', $response->getTargetUrl());
    }

    // A line for adults, a visitor not verified of age: the form posted around the gate is turned back and nothing is paid
    public function testACheckoutForAdultsWaitsForTheAgeVerification(): void
    {
        $basketService = $this->basketService();
        $basketService->method('validate')->willReturn('https://checkout.example/session-1');

        $response = $this->controller($basketService, ageGateClosed: true)->validate(new Request());

        $this->assertNotSame('https://checkout.example/session-1', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame(['flash.age_verification_required'], $this->session->getFlashBag()->get('danger'));
    }

    // The buyer lands here with their files, so a download email that never arrived leaves nobody without what they paid for
    public function testAPaidBasketIsHandedItsDownloadsOnThePage(): void
    {
        $downloads = [['title' => 'A book', 'url' => '/shop/download/abcd', 'size' => 1024]];
        $basket = new Basket()->setStatus(BasketStatus::Paid);

        $parameters = $this->renderedParameters($basket, $downloads);

        $this->assertSame($downloads, $parameters['downloads']);
    }

    // A basket still being paid is never told about its files, whatever a provider would answer
    public function testABasketNotYetPaidIsHandedNoDownload(): void
    {
        $basket = new Basket()->setStatus(BasketStatus::Validated);

        $parameters = $this->renderedParameters($basket, [['title' => 'A book', 'url' => '/shop/download/abcd', 'size' => 1024]]);

        $this->assertSame([], $parameters['downloads']);
    }

    // The entries and the markup showing them are handed over together, the page drawing nothing of its own
    public function testABasketHoldingItemsIsHandedTheProvidersOwnStrip(): void
    {
        $basket = new Basket()->setItems(['product' => [7 => ['item' => ['id' => 7], 'total' => 1000]]]);

        $parameters = $this->displayedParameters($basket, ['a-product'], '@c975LShop/components/Product/Recommendations.html.twig');

        $this->assertSame(['a-product'], $parameters['recommendations']);
        $this->assertSame('@c975LShop/components/Product/Recommendations.html.twig', $parameters['recommendationsTemplate']);
    }

    // An empty basket recommends nothing: no provider is asked, and the page is handed no template to include
    public function testAnEmptyBasketIsHandedNoStripAtAll(): void
    {
        $parameters = $this->displayedParameters(new Basket(), ['a-product'], '@c975LShop/components/Product/Recommendations.html.twig');

        $this->assertSame([], $parameters['recommendations']);
        $this->assertNull($parameters['recommendationsTemplate']);
    }

    /**
     * @param object[] $recommendations what the one registered provider would answer
     *
     * @return array<string, mixed> what the basket page is rendered with
     */
    private function displayedParameters(Basket $basket, array $recommendations, ?string $template): array
    {
        $recommendationRegistry = $this->createStub(BasketRecommendationRegistry::class);
        $recommendationRegistry->method('getRecommendations')->willReturn($recommendations);
        $recommendationRegistry->method('getTemplate')->willReturn($template);

        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('get')->willReturn($basket);

        $parameters = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $context) use (&$parameters): string {
            $parameters = $context;

            return '';
        });

        $this->controller($basketService, null, $twig, $recommendationRegistry)->display(new Request());

        return $parameters;
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

    // The way out carried by every reminder of an unpaid order: the click on the confirmation page, or a mail client's one-click POST, stops the reminders
    public function testPostingTheUnsubscribeAddressStopsTheReminders(): void
    {
        $basket = new Basket()
            ->setNumber('202608-AB-12345')
            ->setShareToken('aaaabbbbccccdddd')
        ;

        $response = $this->controller($this->createStub(BasketServiceInterface::class), twig: $this->createStub(Environment::class))
            ->unsubscribeReminder($basket, Request::create('/', 'POST', ['List-Unsubscribe' => 'One-Click']), $this->entityManager())
        ;

        $this->assertNotNull($basket->getReminderOptOutAt());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($this->flushed);
    }

    // A link scanner opens every link of an e-mail on its arrival: the GET only asks, and writes nothing
    public function testOpeningTheUnsubscribeAddressOnlyAsks(): void
    {
        $basket = new Basket()
            ->setNumber('202608-AB-12345')
            ->setShareToken('aaaabbbbccccdddd')
        ;

        $response = $this->controller($this->createStub(BasketServiceInterface::class), twig: $this->createStub(Environment::class))
            ->unsubscribeReminder($basket, Request::create('/'), $this->entityManager())
        ;

        $this->assertNull($basket->getReminderOptOutAt());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertFalse($this->flushed);
    }

    // The address is guarded by the order number and the share token together, so an order carrying no token is one nobody can be unsubscribed from
    public function testAnOrderThatWasNeverSharedHasNoUnsubscribeAddress(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->createStub(BasketServiceInterface::class))
            ->unsubscribeReminder(new Basket(), Request::create('/'), $this->entityManager())
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

    // A shop declaring one language, which is every shop until it says otherwise: the negotiator then redirects nowhere and varies on nothing
    private static function createSiteLocales(): SiteLocales
    {
        return new SiteLocales(['fr'], 'fr');
    }

    // The real negotiator on that one language, its router naming the route and its parameters
    private function createNegotiator(): LocalizedRouteNegotiator
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => '/' . $name . '?' . http_build_query($parameters)
        );

        return new LocalizedRouteNegotiator(self::createSiteLocales(), new LocaleSwitcher('fr', []), $router);
    }

    // The payer of a shared order contracts, so the waiver of the right of withdrawal is asked of them: a POST without it charges nothing and says why
    public function testASharedOrderSuppliedAtOnceIsNotChargedWithoutThePayersWaiver(): void
    {
        $basket = new Basket()->setNumber('202608-AB-12345')->setShareToken('aaaabbbbccccdddd')->setStatus(BasketStatus::Validated);
        $basket->setTotal(2500);
        $basket->setShipping(0);
        $basket->setContentFlags(Basket::CONTENT_FLAG_DIGITAL);
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->never())->method('payShared');

        $this->controller($basketService, twig: $this->createStub(Environment::class))->sharedPay($basket, new Request(server: ['REQUEST_METHOD' => 'POST']));

        $this->assertSame(['flash.withdrawal_waiver_required'], $this->session->getFlashBag()->get('danger'));
    }

    // Ticked, the payer goes on to the provider
    public function testASharedOrderWaivedByItsPayerGoesToTheProvider(): void
    {
        $basket = new Basket()->setNumber('202608-AB-12345')->setShareToken('aaaabbbbccccdddd')->setStatus(BasketStatus::Validated);
        $basket->setTotal(2500);
        $basket->setShipping(0);
        $basket->setContentFlags(Basket::CONTENT_FLAG_DIGITAL);
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('payShared')->willReturn('https://checkout.example/session-1');

        $response = $this->controller($basketService)->sharedPay($basket, new Request(request: ['withdrawalWaiver' => '1'], server: ['REQUEST_METHOD' => 'POST']));

        $this->assertSame('https://checkout.example/session-1', $response->getTargetUrl());
    }

    // Twig answering the name of the template it was asked to render, which is what the assertions read
    private function twigNaming(): Environment
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnArgument(0);

        return $twig;
    }

    private function controller(BasketServiceInterface $basketService, ?BasketDownloadRegistry $downloadRegistry = null, ?Environment $twig = null, ?BasketRecommendationRegistry $recommendationRegistry = null, bool $accountRequired = false, bool $signedIn = false, bool $csrfValid = false, bool $granted = false, bool $ageGateClosed = false): BasketController
    {
        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('requiresAccount')->willReturn($accountRequired);

        // The translator answers the key itself, so a flash is asserted on the key rather than on a wording
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $ageVerifier = $this->createStub(AgeVerifier::class);
        $ageVerifier->method('isGateClosed')->willReturn($ageGateClosed);

        $controller = new BasketController(
            $this->createStub(ConfigServiceInterface::class),
            $basketService,
            $recommendationRegistry ?? $this->createStub(BasketRecommendationRegistry::class),
            $downloadRegistry ?? $this->createStub(BasketDownloadRegistry::class),
            $translator,
            $this->createStub(InvoiceService::class),
            $this->createNegotiator(),
            self::createSiteLocales(),
            $itemProviderRegistry,
            $ageVerifier,
        );

        $controller->setContainer($this->container($twig, $signedIn, $csrfValid, $granted));

        return $controller;
    }

    // What AbstractController reaches for on these paths: the router for redirectToRoute(), the request stack for addFlash(), and twig for render()
    private function container(?Environment $twig = null, bool $signedIn = false, bool $csrfValid = false, bool $granted = false): ContainerInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => match ($name) {
                'basket_display' => '/shop/basket/display',
                'basket_display_localized' => sprintf('/%s/shop/basket/display', $parameters['_locale']),
                'app_login' => '/app_login?_target_path=' . $parameters['_target_path'],
                default => '/' . $name,
            }
        );

        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack([$request]);

        // The token storage getUser() reads, holding somebody only when the test says the visitor signed in
        $tokenStorage = new TokenStorage();
        if ($signedIn) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('buyer@example.com', null), 'main'));
        }

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($granted);

        $services = ['router' => $router, 'request_stack' => $requestStack, 'twig' => $twig, 'security.token_storage' => $tokenStorage, 'security.csrf.token_manager' => $csrf, 'security.authorization_checker' => $authorizationChecker];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }
}
