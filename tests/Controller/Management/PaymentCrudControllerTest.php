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
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

// A payment is kept as soon as it is tied to a basket or went through
class PaymentCrudControllerTest extends TestCase
{
    private Session $session;

    // A payment tied to a basket, or finished, stays, the admin told why
    public function testARecordedPaymentIsNeverDeleted(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $controller = $this->controller();

        $controller->deleteEntity($entityManager, new Payment()->setBasket(new Basket()));
        $controller->deleteEntity($entityManager, new Payment()->setFinished(true));

        $this->assertSame(['warning' => ['flash.order_not_deletable', 'flash.order_not_deletable']], $this->session->getFlashBag()->all());
    }

    // A payment started and never tied to anything is only noise: deleted
    public function testALoosePaymentIsDeleted(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove');

        $this->controller()->deleteEntity($entityManager, new Payment());
    }

    private function controller(): PaymentCrudController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new PaymentCrudController(
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(TableExporter::class),
            $translator,
            new PaymentGatewayRegistry([], $this->createStub(ConfigServiceInterface::class)),
        );

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $services = ['request_stack' => new RequestStack([$request])];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);
        $controller->setContainer($container);

        return $controller;
    }
}
