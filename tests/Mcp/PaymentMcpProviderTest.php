<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Mcp\PaymentMcpAccess;
use c975L\PaymentBundle\Mcp\PaymentMcpProvider;
use c975L\PaymentBundle\Mcp\PaymentOrderTools;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\PaymentRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class PaymentMcpProviderTest extends TestCase
{
    use QueryBuilderStubTrait;

    // The tools named under the "payment" key, none of them writing
    public function testTools(): void
    {
        $tools = iterator_to_array($this->provider()->getMcpTools(), false);

        $this->assertSame('payment', $this->provider()->getMcpKey());
        $this->assertSame(['payment_list', 'payment_get', 'payment_discount_list', 'payment_order_list', 'payment_order_get'], array_map(static fn ($tool): string => $tool->name, $tools));
        $this->assertSame([false, false, false, false, false], array_map(static fn ($tool): bool => $tool->writes || $tool->destroys, $tools));
    }

    // A payment read with its order's figures, never its customer's name, e-mail or address
    public function testGetMasksTheCustomer(): void
    {
        $basket = new Basket()
            ->setNumber('item-1')
            ->setStatus(BasketStatus::Paid)
            ->setEmail('customer@example.com')
            ->setName('customer-1')
            ->setAddress('address-1')
            ->setCountry('FR')
            ->setTotal(1500)
            ->setDiscountCode('CATALOG');
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrency('EUR');
        $payment->setTransactionId('transaction-1');
        $payment->setFinished(true);
        $payment->setBasket($basket);
        $repository = $this->createStub(PaymentRepository::class);
        $repository->method('find')->willReturn($payment);

        $found = $this->provider($repository)->get(1);

        $this->assertSame(['item-1', 'paid', 1500, 'CATALOG', 'FR', true], [$found['order'], $found['status'], $found['total'], $found['discountCode'], $found['country'], $found['finished']]);
        $this->assertStringNotContainsString('customer', (string) json_encode($found));
        $this->assertStringNotContainsString('address-1', (string) json_encode($found));
    }

    // A gift card's code read masked to its last characters on a payment: the full code is the money still on the card
    public function testGetMasksAGiftCardCode(): void
    {
        $basket = new Basket()
            ->setDiscountCode('CATALOG-ITEM-1234')
            ->setDiscountKind(Basket::CODE_KIND_GIFT_CARD);
        $payment = new Payment();
        $payment->setBasket($basket);
        $repository = $this->createStub(PaymentRepository::class);
        $repository->method('find')->willReturn($payment);

        $found = $this->provider($repository)->get(1);

        $this->assertSame('…1234', $found['discountCode']);
    }

    // The payments filtered on going through when asked, every one without it
    public function testListFiltersTheFinished(): void
    {
        $payment = new Payment();
        $payment->setAmount(900);
        $payment->setFinished(false);
        $repository = $this->createStub(PaymentRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(fn (string $alias): QueryBuilder => $this->builder(Payment::class, $alias, [$payment]));
        $provider = $this->provider($repository);

        $found = $provider->list(false);

        $this->assertStringContainsString('p.isFinished = :finished', $this->dql);
        $this->assertFalse($this->parameters['finished']);
        $this->assertSame([900, false, null], [$found[0]['amount'], $found[0]['finished'], $found[0]['order']]);

        $provider->list();

        $this->assertStringNotContainsString('isFinished', $this->dql);
    }

    // The search words looked for uppercase, their wildcards and backslash taken literally
    public function testDiscountsEscapeTheSearch(): void
    {
        $discount = new Discount()->setCode('catalog');
        $repository = $this->createStub(DiscountRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(fn (string $alias): QueryBuilder => $this->builder(Discount::class, $alias, [$discount]));

        $found = $this->provider(discountRepository: $repository)->discounts(' 5%_off\\ ');

        $this->assertStringContainsString('d.code LIKE :words', $this->dql);
        $this->assertSame('%5\\%\\_OFF\\\\%', $this->parameters['words']);
        $this->assertSame('CATALOG', $found[0]['code']);
    }

    // An unknown payment answers as such
    public function testGetUnknown(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->get(1);
    }

    // An account that does not administer the payments is refused every tool
    public function testRefusedWithoutTheRole(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider(granted: false)->discounts();
    }

    // The provider for an account administering the payments or not
    private function provider(?PaymentRepository $repository = null, bool $granted = true, ?DiscountRepository $discountRepository = null): PaymentMcpProvider
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_ADMIN');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);
        $access = new PaymentMcpAccess($config, $security);

        return new PaymentMcpProvider($repository ?? $this->createStub(PaymentRepository::class), $discountRepository ?? $this->createStub(DiscountRepository::class), $access, new PaymentOrderTools($this->createStub(BasketRepository::class), $access));
    }
}
