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
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Mcp\PaymentMcpAccess;
use c975L\PaymentBundle\Mcp\PaymentOrderTools;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class PaymentOrderToolsTest extends TestCase
{
    use QueryBuilderStubTrait;

    // The two tools, none of them writing
    public function testTools(): void
    {
        $tools = iterator_to_array($this->tools()->getMcpTools(), false);

        $this->assertSame(['payment_order_list', 'payment_order_get'], array_map(static fn ($tool): string => $tool->name, $tools));
        $this->assertSame([false, false], array_map(static fn ($tool): bool => $tool->writes || $tool->destroys, $tools));
    }

    // Without a status, every order not archived, the latest first and fifty at most
    public function testListDefaultsToTheOrdersNotArchived(): void
    {
        $tools = $this->tools($this->listing([$this->order()]));

        $found = $tools->list();

        $this->assertStringContainsString('b.archived IS NULL', $this->dql);
        $this->assertStringContainsString('b.status IN (:statuses)', $this->dql);
        $this->assertStringContainsString('ORDER BY b.creation DESC', $this->dql);
        $this->assertSame(BasketStatus::RECORDED, $this->parameters['statuses']);
        $this->assertSame(50, $this->maxResults);
        $this->assertSame(['item-1', 'paid', ['items']], [$found[0]['number'], $found[0]['status'], $found[0]['toSend']]);
    }

    // The paid orders with a parcel still to post, each flag paired with its own date
    public function testListToSend(): void
    {
        $this->tools($this->listing([]))->list('to_send', limit: 500);

        $this->assertStringContainsString('b.status = :status', $this->dql);
        $this->assertStringContainsString('BIT_AND(b.contentflags, :physical) > 0 AND b.itemsShipped IS NULL', $this->dql);
        $this->assertStringContainsString('BIT_AND(b.contentflags, :counterparts) > 0 AND b.counterpartsShipped IS NULL', $this->dql);
        $this->assertSame([BasketStatus::Paid, Basket::CONTENT_FLAG_PHYSICAL, Basket::CONTENT_FLAG_CF_SHIPPING], [$this->parameters['status'], $this->parameters['physical'], $this->parameters['counterparts']]);
        $this->assertSame(PaymentOrderTools::LIMIT, $this->maxResults);
    }

    // A status read as the back office files it, the archive apart
    public function testListByStatus(): void
    {
        $tools = $this->tools($this->listing([]));

        $tools->list('shipped', limit: 0);

        $this->assertSame(BasketStatus::Shipped, $this->parameters['status']);
        $this->assertStringContainsString('b.archived IS NULL', $this->dql);
        $this->assertSame(1, $this->maxResults);

        $tools->list('archived');

        $this->assertStringContainsString('b.archived IS NOT NULL', $this->dql);
        $this->assertSame(BasketStatus::RECORDED, $this->parameters['statuses']);
    }

    // The days placed, the last one included
    public function testListByDays(): void
    {
        $this->tools($this->listing([]))->list(from: '2026-11-01', to: '2026-11-22');

        $this->assertStringContainsString('b.creation >= :from', $this->dql);
        $this->assertStringContainsString('b.creation < :to', $this->dql);
        $this->assertSame(['2026-11-01', '2026-11-23'], [$this->parameters['from']->format('Y-m-d'), $this->parameters['to']->format('Y-m-d')]);
    }

    // An unknown status, a day PHP would roll over and days given backwards are refused
    #[DataProvider('refusedFilters')]
    public function testListRefuses(?string $status, ?string $from, ?string $to): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools($this->listing([]))->list($status, $from, $to);
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function refusedFilters(): iterable
    {
        yield 'status' => ['new', null, null];
        yield 'day' => [null, '2026-02-30', null];
        yield 'backwards' => [null, '2026-11-22', '2026-11-01'];
    }

    // An order read with its lines, totals, discount and shipping, never what names or reaches its customer
    public function testGetMasksTheCustomer(): void
    {
        $payment = new Payment();
        $payment->setGateway('stripe');
        $payment->setFinished(true);
        $payment->setTransactionId('transaction-secret');
        $basket = $this->order()
            ->setEmail('customer@example.com')
            ->setName('customer-name')
            ->setAddress('address-1')
            ->setCity('city-1')
            ->setZip('zip-1')
            ->setCompany('company-1')
            ->setVatNumber('vat-1')
            ->setMessage('message-1')
            ->setSecurityToken('token-security')
            ->setRecoveryToken('token-recovery')
            ->setShareToken('token-share')
            ->setGiftCardRecipientEmail('recipient@example.com')
            ->setGiftCardRecipientMessage('recipient-message')
            ->setCheckoutData(['product' => ['secret' => 'checkout-secret']])
            ->setDiscountCode('GIFT-CARD-SECRET-9876')
            ->setDiscountKind(Basket::CODE_KIND_GIFT_CARD)
            ->setDiscountAmount(200);
        $basket->setPayment($payment);
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findOneBy')->willReturn($basket);

        $found = $this->tools($repository)->get(' item-1 ');
        $json = (string) json_encode($found);

        $this->assertSame([['kind' => 'product', 'label' => 'catalog (item-1)', 'quantity' => 2, 'unitPrice' => 750, 'total' => 1500, 'totalVat' => 250]], $found['lines']);
        $this->assertSame(['…9876', 200, 1300, ['items' => true, 'counterparts' => false], 'FR'], [$found['discountCode'], $found['discountAmount'], $found['payable'], $found['ships'], $found['country']]);
        $this->assertSame(['stripe', true], [$found['payment']['gateway'], $found['payment']['finished']]);
        foreach (['customer', 'address-1', 'city-1', 'zip-1', 'company-1', 'vat-1', 'message', 'token-', 'recipient', 'secret', 'SECRET', 'engraving'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    // A basket that never became an order, or no basket, answers as no order
    public function testGetRefusesWhatIsNoOrder(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findOneBy')->willReturn(new Basket()->setNumber('item-1')->setStatus(BasketStatus::Validated));

        $this->expectException(ToolCallException::class);
        $this->tools($repository)->get('item-1');
    }

    // An account that does not administer the payments is refused the list
    public function testListRefusedWithoutTheRole(): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools(granted: false)->list();
    }

    // An account that does not administer the payments is refused an order
    public function testGetRefusedWithoutTheRole(): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools(granted: false)->get('item-1');
    }

    // A paid order of one product line still to post, a provider's extra key beside the line
    private function order(): Basket
    {
        return new Basket()
            ->setNumber('item-1')
            ->setStatus(BasketStatus::Paid)
            ->setCountry('FR')
            ->setCurrency('EUR')
            ->setTotal(1500)
            ->setShipping(0)
            ->setContentFlags(Basket::CONTENT_FLAG_PHYSICAL)
            ->setItems(['product' => [1 => [
                'item' => ['title' => 'item-1', 'price' => 750],
                'parent' => ['title' => 'catalog'],
                'quantity' => 2,
                'total' => 1500,
                'totalVat' => 250,
                'engraving' => 'engraving-1',
            ]]]);
    }

    // A repository whose query builder is read back, the rows given
    /** @param list<Basket> $rows */
    private function listing(array $rows): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(fn (string $alias): QueryBuilder => $this->builder(Basket::class, $alias, $rows));

        return $repository;
    }

    // The tools for an account administering the payments or not
    private function tools(?BasketRepository $repository = null, bool $granted = true): PaymentOrderTools
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_ADMIN');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return new PaymentOrderTools($repository ?? $this->createStub(BasketRepository::class), new PaymentMcpAccess($config, $security));
    }
}
