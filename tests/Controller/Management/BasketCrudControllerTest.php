<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class BasketCrudControllerTest extends TestCase
{
    private Session $session;

    // The exported rows must never carry the token that opens a customer's order-tracking url (see BasketController)
    public function testFetchExportRowsDropsTheSecurityToken(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'number' => 'AB12', 'security_token' => 'a1b2c3d4e5f60718', 'email' => 'buyer@example.com'],
        ]);

        // The column is resolved through the mapping, its name depending on the naming strategy the site configures
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getColumnName')->willReturn('security_token');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $controller = new BasketCrudController(
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->createStub(ConfigServiceInterface::class),
            $entityManager,
            $this->createStub(TableExporter::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(BasketRepository::class),
            $this->createStub(PdfGeneratorInterface::class),
            $this->createStub(BasketServiceInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
        );

        $rows = new \ReflectionMethod($controller, 'fetchExportRows')->invoke($controller);

        $this->assertSame([['id' => 1, 'number' => 'AB12', 'email' => 'buyer@example.com']], $rows);
    }

    // The gesture that closes an order charged and not delivered: with its token it delivers again, and says whether that went through
    public function testRetryingADeliveryWithItsTokenDeliversAndSaysSo(): void
    {
        $basket = new Basket()->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true));
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->once())->method('paid')->with($basket)->willReturnCallback(static function (Basket $basket): void {
            $basket->setStatus(BasketStatus::Paid);
        });

        $controller = $this->controllerFor($basket, $basketService, csrfValid: true);
        $controller->retryDelivery(new Request(['entityId' => 1, 'token' => 'good']));

        $this->assertSame(['flash.delivery_retried'], $this->flashes()['success'] ?? []);
    }

    // Still not covered, or still throwing: the admin is told, and given no error page
    public function testARetryThatDoesNotDeliverSaysSo(): void
    {
        $basket = new Basket()->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true));
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('paid')->willThrowException(new \RuntimeException('Still down'));

        $controller = $this->controllerFor($basket, $basketService, csrfValid: true);
        $controller->retryDelivery(new Request(['entityId' => 1, 'token' => 'good']));

        $this->assertSame(['flash.delivery_still_held'], $this->flashes()['danger'] ?? []);
    }

    // Without its token the url does nothing
    public function testARetryWithoutItsTokenDeliversNothing(): void
    {
        $basket = new Basket()->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true));
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->never())->method('paid');

        $this->controllerFor($basket, $basketService, csrfValid: false)->retryDelivery(new Request(['entityId' => 1, 'token' => 'forged']));
    }

    // Only an order charged and undelivered is delivered from here: a basket waiting for its money is not one
    public function testARetryOnAnOrderNotChargedDeliversNothing(): void
    {
        $basket = new Basket()->setStatus(BasketStatus::Validated);
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->never())->method('paid');

        $this->expectException(NotFoundHttpException::class);
        $this->controllerFor($basket, $basketService, csrfValid: true)->retryDelivery(new Request(['entityId' => 1, 'token' => 'good']));
    }

    // A checkout removed from the back-office is called off at its provider; an order keeps what it spent, a charged one included, until a refund credits it
    public function testDeletingACheckoutCallsItOffAndAnOrderIsLeftAlone(): void
    {
        $checkout = new Basket()->setStatus(BasketStatus::Validated);
        $basketService = $this->createMock(BasketServiceInterface::class);
        $basketService->expects($this->once())->method('callOffCheckout')->with($checkout)->willReturn(true);
        $controller = $this->controllerFor($checkout, $basketService, csrfValid: true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(3))->method('remove');

        $controller->deleteEntity($entityManager, $checkout);
        $controller->deleteEntity($entityManager, new Basket()->setStatus(BasketStatus::Paid));
        $controller->deleteEntity($entityManager, new Basket()->setStatus(BasketStatus::Validated)->setPayment(new Payment()->setFinished(true)));
    }

    // Its checkout found paid, the basket is an order now: it stays, and the admin is told why
    public function testACheckoutFoundPaidIsNotDeleted(): void
    {
        $checkout = new Basket()->setStatus(BasketStatus::Validated);
        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('callOffCheckout')->willReturn(false);
        $controller = $this->controllerFor($checkout, $basketService, csrfValid: true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller->deleteEntity($entityManager, $checkout);

        $this->assertSame(['warning' => ['flash.basket_kept_paid']], $this->flashes());
    }

    private function flashes(): array
    {
        return $this->session->getFlashBag()->all();
    }

    private function controllerFor(Basket $basket, BasketServiceInterface $basketService, bool $csrfValid): BasketCrudController
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn($basket);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $urlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $urlGenerator->method('unsetAll')->willReturnSelf();
        $urlGenerator->method('setController')->willReturnSelf();
        $urlGenerator->method('setAction')->willReturnSelf();
        $urlGenerator->method('generateUrl')->willReturn('/admin/basket');

        $controller = new BasketCrudController(
            $urlGenerator,
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(TableExporter::class),
            $translator,
            $repository,
            $this->createStub(PdfGeneratorInterface::class),
            $basketService,
            $this->createStub(CsrfTokenManagerInterface::class),
        );

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $services = ['request_stack' => new RequestStack([$request]), 'security.csrf.token_manager' => $csrf, 'security.authorization_checker' => $authorizationChecker];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);
        $controller->setContainer($container);

        return $controller;
    }
}
