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
use c975L\ConfigBundle\Management\HealthCheckSiteWideInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Management\BasketIntegrityHealthCheckProvider;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\PaymentRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The three ways an order goes wrong without saying so: nobody was told, nothing was logged, and the two rows that disagree are only ever read apart
class BasketIntegrityHealthCheckProviderTest extends TestCase
{
    // Six checks reading the whole shop at once, not one page of it: without this the rows land in the Health check page's "Pages" table, as if the site root carrying a #fragment were a page of its own
    public function testTheProviderDeclaresItselfSiteWide(): void
    {
        $this->assertInstanceOf(HealthCheckSiteWideInterface::class, $this->provider());
    }

    // The dashboard has to say "checked, nothing found" and not just fall silent - silence is what a check that never ran looks like
    public function testASoundShopReportsEveryCheckAsGreen(): void
    {
        $rows = $this->provider()->runChecks();

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame(HealthCheckResult::STATUS_OK, $row['status'], $row['url']);
            $this->assertSame([], $row['details']['offenders']);
        }
    }

    // The one nothing else can say: the money left the customer's account and the order stayed where it was
    public function testAChargedPaymentWithoutItsDeliveredOrderIsReported(): void
    {
        $basket = $this->order('2026-000042', 'validated');
        $payment = new Payment()->setAmount(4500)->setCurrency('EUR')->setBasket($basket);
        new \ReflectionProperty(Payment::class, 'id')->setValue($payment, 7);

        $row = $this->row($this->provider(['findFinishedWithoutDeliveredBasket' => [$payment]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_CHARGED_NOT_DELIVERED);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertCount(1, $row['details']['offenders']);
        $this->assertSame('2026-000042', $row['details']['offenders'][0]['number']);
        $this->assertSame(42, $row['details']['offenders'][0]['basketId'], 'The order is what the dashboard links to when there is one');
        $this->assertStringContainsString('validated', $row['details']['offenders'][0]['info'], 'The status the order is stuck at is what says where to pick it up');
    }

    // A charge whose payment row carries no basket at all is reported too, its own id being all there is to open
    public function testAChargeAnsweringForNoOrderCarriesItsPaymentId(): void
    {
        $payment = new Payment()->setAmount(1000)->setCurrency('EUR');
        new \ReflectionProperty(Payment::class, 'id')->setValue($payment, 7);

        $row = $this->row($this->provider(['findFinishedWithoutDeliveredBasket' => [$payment]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_CHARGED_NOT_DELIVERED);

        $this->assertSame(7, $row['details']['offenders'][0]['paymentId']);
        $this->assertNull($row['details']['offenders'][0]['basketId']);
    }

    public function testADeliveredOrderWithoutAConfirmedPaymentIsReported(): void
    {
        $row = $this->row($this->provider(['findDeliveredWithoutFinishedPayment' => [$this->order('2026-000043', 'paid')]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_DELIVERED_UNPAID);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame('no payment', $row['details']['offenders'][0]['info']);
    }

    // The query only narrows the candidates down - what a payment must match is what Basket::getPayable() charges, floor included
    public function testAnAmountThatMatchesTheOrderIsNotReported(): void
    {
        $basket = $this->order('2026-000044', 'paid')->setTotal(4000)->setShipping(500);
        $basket->setPayment($this->payment(4500, 'eur'));

        $row = $this->row($this->provider(['findWithPaymentAmountMismatch' => [$basket]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_AMOUNT_MISMATCH);

        $this->assertSame(HealthCheckResult::STATUS_OK, $row['status'], 'The currency is compared whatever its case, "eur" and "EUR" being one and the same');
    }

    public function testAnAmountThatDoesNotMatchTheOrderIsReportedWithBothFigures(): void
    {
        $basket = $this->order('2026-000045', 'paid')->setTotal(4000)->setShipping(500);
        $basket->setPayment($this->payment(4000, 'EUR'));

        $row = $this->row($this->provider(['findWithPaymentAmountMismatch' => [$basket]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_AMOUNT_MISMATCH);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame('40.00 EUR != 45.00 EUR', $row['details']['offenders'][0]['info']);
    }

    public function testADeliveredOrderWithoutAnInvoiceNumberIsReported(): void
    {
        $row = $this->row($this->provider(['findDeliveredWithoutNumber' => [$this->order(null, 'paid')]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_MISSING_NUMBER);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
    }

    public function testLinesThatDoNotAddUpToTheirOwnTotalAreReported(): void
    {
        $basket = $this->order('2026-000046', 'paid')->setTotal(5000);
        $basket->setQuantity(1)->setItems(['product' => [12 => ['total' => 4000, 'quantity' => 1]]]);

        $row = $this->row($this->provider(['findOrdersSince' => [$basket]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_TOTAL_MISMATCH);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame('lines 4000 != total 5000', $row['details']['offenders'][0]['info']);
    }

    // An order written before this bundle stored a total per line is not a defect, and a check that reports one is a check that gets switched off
    public function testAnOrderWhoseLinesCannotBeAddedUpIsLeftAlone(): void
    {
        $basket = $this->order('2026-000047', 'paid')->setTotal(5000);
        $basket->setQuantity(1)->setItems(['product' => [12 => ['title' => 'An order from another age']]]);

        $row = $this->row($this->provider(['findOrdersSince' => [$basket]])->runChecks(), BasketIntegrityHealthCheckProvider::ROW_TOTAL_MISMATCH);

        $this->assertSame(HealthCheckResult::STATUS_OK, $row['status']);
    }

    public function testABasketHoldingAWithdrawnArticleIsReported(): void
    {
        $basket = $this->order('2026-000048', 'new');
        $basket->setItems(['product' => [12 => ['total' => 100, 'quantity' => 1]]]);

        $row = $this->row($this->provider(['findPayable' => [$basket]], false)->runChecks(), BasketIntegrityHealthCheckProvider::ROW_UNRESOLVABLE_ITEMS);

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame('product #12', $row['details']['offenders'][0]['info']);
    }

    // The bundle selling that kind was removed while baskets still held it: the registry throws on get(), and the row must say so rather than take the five other checks down with it
    public function testABasketHoldingAKindNoBundleSellsIsReported(): void
    {
        $basket = $this->order('2026-000049', 'new');
        $basket->setItems(['crowdfunding' => [3 => ['total' => 100, 'quantity' => 1]]]);

        $rows = $this->provider(['findPayable' => [$basket]])->runChecks();
        $row = $this->row($rows, BasketIntegrityHealthCheckProvider::ROW_UNRESOLVABLE_ITEMS);

        $this->assertCount(6, $rows);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame('crowdfunding (unknown kind)', $row['details']['offenders'][0]['info']);
    }

    // HealthCheckRunner drops every row of a provider that throws, and no rows at all reads as "nothing to report"
    public function testAFailingCheckReportsItselfWithoutTakingTheOthersDown(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('findDeliveredWithoutFinishedPayment')->willThrowException(new \RuntimeException('Table gone'));
        foreach (['findWithPaymentAmountMismatch', 'findDeliveredWithoutNumber', 'findOrdersSince', 'findPayable'] as $method) {
            $repository->method($method)->willReturn([]);
        }

        $rows = new BasketIntegrityHealthCheckProvider($repository, $this->paymentRepository([]), $this->registry(true), $this->siteUrlResolver('https://example.com/'), $this->translator())->runChecks();

        $this->assertCount(6, $rows);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->row($rows, BasketIntegrityHealthCheckProvider::ROW_DELIVERED_UNPAID)['status']);
        $this->assertSame('Table gone', $this->row($rows, BasketIntegrityHealthCheckProvider::ROW_DELIVERED_UNPAID)['details']['error']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->row($rows, BasketIntegrityHealthCheckProvider::ROW_MISSING_NUMBER)['status']);
    }

    // Same guard as every site-wide check: without a site url there is nothing to key a row on
    public function testNothingIsCheckedWithoutASiteUrl(): void
    {
        $provider = new BasketIntegrityHealthCheckProvider(
            $this->createStub(BasketRepository::class),
            $this->paymentRepository([]),
            $this->registry(true),
            $this->siteUrlResolver(null),
            $this->translator(),
        );

        $this->assertSame([], $provider->runChecks());
    }

    /**
     * @param array<string, list<object>> $found      what each query hands back
     * @param bool                        $resolvable whether the article a basket names is still on sale
     */
    private function provider(array $found = [], bool $resolvable = true): BasketIntegrityHealthCheckProvider
    {
        $basketRepository = $this->createStub(BasketRepository::class);
        foreach (['findDeliveredWithoutFinishedPayment', 'findWithPaymentAmountMismatch', 'findDeliveredWithoutNumber', 'findOrdersSince', 'findPayable'] as $method) {
            $basketRepository->method($method)->willReturn($found[$method] ?? []);
        }

        return new BasketIntegrityHealthCheckProvider(
            $basketRepository,
            $this->paymentRepository($found['findFinishedWithoutDeliveredBasket'] ?? []),
            $this->registry($resolvable),
            $this->siteUrlResolver('https://example.com/'),
            $this->translator(),
        );
    }

    private function paymentRepository(array $found): PaymentRepository
    {
        $repository = $this->createStub(PaymentRepository::class);
        $repository->method('findFinishedWithoutDeliveredBasket')->willReturn($found);

        return $repository;
    }

    // Only "product" is registered, so a basket naming any other kind is one whose bundle is gone
    private function registry(bool $resolvable): BasketItemProviderRegistry
    {
        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('getKind')->willReturn('product');
        $provider->method('findItem')->willReturn($resolvable ? new \stdClass() : null);

        return new BasketItemProviderRegistry([$provider]);
    }

    private function siteUrlResolver(?string $siteRoot): SiteUrlResolver
    {
        $resolver = $this->createStub(SiteUrlResolver::class);
        $resolver->method('siteRoot')->willReturn($siteRoot);

        return $resolver;
    }

    // The translation ids themselves, so the assertions read what the provider asked for rather than what a catalog answers
    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []) => $id . ($parameters['%message%'] ?? ''));

        return $translator;
    }

    // The id is posed the way the other suites of this bundle do it: what the provider hands the dashboard is a link, and a link is an id
    private function order(?string $number, string $status, int $id = 42): Basket
    {
        $basket = new Basket()
            ->setNumber($number)
            ->setStatus($status)
            ->setCurrency('EUR')
            ->setTotal(0)
            ->setShipping(0)
            ->setQuantity(0)
            ->setItems([])
            ->setCreation(new \DateTime())
        ;

        new \ReflectionProperty(Basket::class, 'id')->setValue($basket, $id);

        return $basket;
    }

    private function payment(int $amount, string $currency): Payment
    {
        $payment = new Payment()->setAmount($amount)->setCurrency($currency);
        new \ReflectionProperty(Payment::class, 'id')->setValue($payment, 7);

        return $payment;
    }

    private function row(array $rows, string $suffix): array
    {
        foreach ($rows as $row) {
            if (str_ends_with($row['url'], $suffix)) {
                return $row;
            }
        }

        $this->fail('No row was reported for ' . $suffix);
    }
}
