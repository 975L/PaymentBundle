<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Controller\StoreNotificationController;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Service\InAppPurchaseServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

// What a store is answered: 200 once read, 400 on a forged payload so it is not replayed for nothing, 404 on a store nobody registers
class StoreNotificationControllerTest extends TestCase
{
    public function testARefundIsHandedToThePurchaseService(): void
    {
        $refund = new InAppTransaction('apple', 'back.a', '1', event: PaymentEvent::Refunded);
        $service = $this->createMock(InAppPurchaseServiceInterface::class);
        $service->expects($this->once())->method('applyNotification')->with($refund);

        $this->assertSame(200, $this->controller(static fn () => $refund, $service)->notify(new Request(), 'apple')->getStatusCode());
    }

    public function testAForgedPayloadIsAnswered400(): void
    {
        $this->assertSame(400, $this->controller(static fn () => throw new InvalidNotificationException('forged'))->notify(new Request(), 'apple')->getStatusCode());
    }

    public function testAnUnknownStoreIsAnswered404(): void
    {
        $this->assertSame(404, $this->controller(static fn () => null)->notify(new Request(), 'amazon')->getStatusCode());
    }

    private function controller(\Closure $read, ?InAppPurchaseServiceInterface $service = null): StoreNotificationController
    {
        $store = $this->createStub(InAppStoreInterface::class);
        $store->method('getSlug')->willReturn('apple');
        $store->method('readNotification')->willReturnCallback($read);

        return new StoreNotificationController(new InAppStoreRegistry([$store]), $service ?? $this->createStub(InAppPurchaseServiceInterface::class), new NullLogger());
    }
}
