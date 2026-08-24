<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use c975L\PaymentBundle\Management\BasketIntegrityHealthCheckAdviceProvider;
use c975L\PaymentBundle\Management\BasketIntegrityHealthCheckProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// A count of stalled orders is only worth reading if the next click opens the first of them
class BasketIntegrityHealthCheckAdviceProviderTest extends TestCase
{
    /** @var list<string> */
    private array $controllers = [];

    public function testEachOffendingOrderIsListedUnderItsRow(): void
    {
        $advice = $this->buildAdvice([
            ['basketId' => 42, 'paymentId' => 7, 'number' => '2026-000042', 'info' => 'validated'],
            ['basketId' => 43, 'paymentId' => null, 'number' => '2026-000043', 'info' => 'no payment'],
        ]);

        $line = $advice['basket-integrity|https://example.com/#charged-not-delivered'][0];

        $this->assertCount(2, $line['items']);
        $this->assertSame('label.health_check_advice_basket_offender', $line['items'][0]['text']);
        $this->assertSame([BasketCrudController::class, BasketCrudController::class], $this->controllers, 'An order is opened on its own screen');
    }

    // The row reporting a charge that answers for no order at all: the payment is the only thing there is to open
    public function testAChargeWithoutAnOrderLinksToItsPayment(): void
    {
        $this->buildAdvice([['basketId' => null, 'paymentId' => 7, 'number' => null, 'info' => 'no basket']]);

        $this->assertSame([PaymentCrudController::class], $this->controllers);
    }

    public function testAGreenRowCarriesNoAdvice(): void
    {
        $this->assertSame([], $this->buildAdvice([]));
    }

    // Every kind's results are handed to every advice provider, this one answering only for its own
    public function testAnotherKindIsLeftAlone(): void
    {
        $result = new HealthCheckResult()
            ->setKind('pagespeed')
            ->setUrl('https://example.com/')
            ->setDetails(['offenders' => [['basketId' => 42, 'number' => 'X', 'info' => '']]])
        ;

        $this->assertSame([], $this->provider()->buildAdvice([$result]));
    }

    private function buildAdvice(array $offenders): array
    {
        $result = new HealthCheckResult()
            ->setKind(BasketIntegrityHealthCheckProvider::KIND)
            ->setUrl('https://example.com/' . BasketIntegrityHealthCheckProvider::ROW_CHARGED_NOT_DELIVERED)
            ->setDetails(['offenders' => $offenders])
        ;

        return $this->provider()->buildAdvice([$result]);
    }

    private function provider(): BasketIntegrityHealthCheckAdviceProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new BasketIntegrityHealthCheckAdviceProvider($this->adminUrlGenerator(), $translator);
    }

    // Records the CRUD controller each link is generated for, an advice line keeping only the url it came back with
    private function adminUrlGenerator(): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('setEntityId')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/generated');
        $generator->method('setController')->willReturnCallback(function (string $controller) use ($generator): AdminUrlGeneratorInterface {
            $this->controllers[] = $controller;

            return $generator;
        });

        return $generator;
    }
}
