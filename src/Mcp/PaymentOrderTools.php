<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Mcp;

use c975L\ConfigBundle\Mcp\McpTool;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Server\Exception\ToolCallException;

// The orders on the site's MCP endpoint, as on their back-office screen (see BasketCrudController): listed and read by their number, never changed, and never with what names or reaches the customer - e-mail, name, address, company, message, tokens or a gift card's code
class PaymentOrderTools
{
    // Orders a list gives at most
    public const int LIMIT = 100;

    // The statuses a list is filtered on: to_send the paid orders with a parcel still to post, archived those the archive holds
    public const array STATUSES = ['to_send', 'paid', 'shipped', 'refunded', 'archived'];

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly PaymentMcpAccess $access,
    ) {
    }

    // The tools of the orders, under the payment key of PaymentMcpProvider
    /** @return iterable<McpTool> */
    public function getMcpTools(): iterable
    {
        yield new McpTool('payment_order_list', 'Orders', 'The latest orders, paid, shipped or refunded, the archived ones apart, with their number, status, totals and what is still to send; filtered on a status and on the days they were placed.', $this->list(...));
        yield new McpTool('payment_order_get', 'Order', 'An order by its number: status, dates, lines with their frozen totals, discount, shipping, refund, invoice and what is still to send, without its customer.', $this->get(...));
    }

    // The latest orders, filtered on a status and on the days they were placed, the last day included
    /**
     * @param ?string $status to_send (paid, a parcel still to post), paid, shipped, refunded or archived; every order not archived when empty
     * @param ?string $from   first day placed, YYYY-MM-DD
     * @param ?string $to     last day placed, YYYY-MM-DD
     * @param ?int    $limit  orders given at most, 1 to 100, 50 by default
     *
     * @return list<array{number: ?string, status: string, test: bool, created: ?string, currency: ?string, quantity: ?int, total: ?int, shipping: ?int, discountAmount: int, payable: int, refundedAmount: int, toSend: list<string>, archived: ?string}>
     */
    public function list(?string $status = null, ?string $from = null, ?string $to = null, ?int $limit = null): array
    {
        $this->access->guard();
        $builder = $this->basketRepository->createQueryBuilder('b')
            ->orderBy('b.creation', \SortDirection::Descending)
            ->setMaxResults(max(1, min(self::LIMIT, $limit ?? 50)));
        $this->filterStatus($builder, $status);
        $this->filterDays($builder, $this->access->day($from), $this->access->day($to));

        return array_map($this->summary(...), $builder->getQuery()->getResult());
    }

    // An order by its number with its lines, its discount, its shipping and its documents
    /**
     * @param string $number number of the order, as a payment names it
     *
     * @return array<string, mixed>
     */
    public function get(string $number): array
    {
        $this->access->guard();
        $basket = $this->basketRepository->findOneBy(['number' => trim($number)]);
        if (!$basket instanceof Basket || !$basket->isOrder()) {
            throw new ToolCallException(sprintf('No order %s.', $number));
        }
        $flags = $basket->getContentFlags();
        $payment = $basket->getPayment();

        return [
            ...$this->summary($basket),
            'modified' => $basket->getModification()?->format('Y-m-d H:i'),
            'lines' => $this->lines($basket),
            'discountKind' => $basket->getDiscountKind(),
            'discountCode' => self::code($basket),
            'ships' => ['items' => 0 !== ($flags & Basket::CONTENT_FLAG_PHYSICAL), 'counterparts' => 0 !== ($flags & Basket::CONTENT_FLAG_CF_SHIPPING)],
            'country' => $basket->getCountry(),
            'itemsShipped' => $basket->getItemsShipped()?->format('Y-m-d H:i'),
            'counterpartsShipped' => $basket->getCounterpartsShipped()?->format('Y-m-d H:i'),
            'downloaded' => $basket->getDownloaded()?->format('Y-m-d H:i'),
            'refunded' => $basket->getRefundedAt()?->format('Y-m-d H:i'),
            'invoice' => $basket->getInvoiceNumber(),
            'invoiceDate' => $basket->getInvoiceDate()?->format('Y-m-d'),
            'creditNote' => $basket->getCreditNoteNumber(),
            'payment' => null === $payment ? null : ['id' => $payment->getId(), 'gateway' => $payment->getGateway(), 'finished' => true === $payment->isFinished()],
        ];
    }

    // The code an order was charged with, a gift card's masked to its last characters: the full code is the money still on the card
    public static function code(Basket $basket): ?string
    {
        return Basket::CODE_KIND_GIFT_CARD === $basket->getDiscountKind() ? $basket->getMaskedDiscountCode() : $basket->getDiscountCode();
    }

    // Orders only, by the status asked, the archive apart unless it is the one asked, as the back office lists them
    private function filterStatus(QueryBuilder $builder, ?string $status): void
    {
        $status = trim((string) $status);
        if ('' !== $status && !\in_array($status, self::STATUSES, true)) {
            throw new ToolCallException(sprintf('"%s" is not a status, give one of %s.', $status, implode(', ', self::STATUSES)));
        }

        $builder->andWhere('archived' === $status ? 'b.archived IS NOT NULL' : 'b.archived IS NULL');
        if (\in_array($status, ['', 'archived'], true)) {
            $builder->andWhere('b.status IN (:statuses)')->setParameter('statuses', BasketStatus::RECORDED);

            return;
        }

        $builder->andWhere('b.status = :status')->setParameter('status', 'to_send' === $status ? BasketStatus::Paid : BasketStatus::from($status));
        // Each flag paired with its own date, as BasketRepository::findAwaitingShipping() reads them
        if ('to_send' === $status) {
            $builder->andWhere('(BIT_AND(b.contentflags, :physical) > 0 AND b.itemsShipped IS NULL) OR (BIT_AND(b.contentflags, :counterparts) > 0 AND b.counterpartsShipped IS NULL)')
                ->setParameter('physical', Basket::CONTENT_FLAG_PHYSICAL)
                ->setParameter('counterparts', Basket::CONTENT_FLAG_CF_SHIPPING);
        }
    }

    // The days the orders were placed, the last one included
    private function filterDays(QueryBuilder $builder, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if (null !== $from && null !== $to && $to < $from) {
            throw new ToolCallException('The days run from the first to the last.');
        }
        if (null !== $from) {
            $builder->andWhere('b.creation >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $builder->andWhere('b.creation < :to')->setParameter('to', $to->modify('+1 day'));
        }
    }

    // An order in a list, named by its number, never by its customer
    /** @return array{number: ?string, status: string, test: bool, created: ?string, currency: ?string, quantity: ?int, total: ?int, shipping: ?int, discountAmount: int, payable: int, refundedAmount: int, toSend: list<string>, archived: ?string} */
    private function summary(Basket $basket): array
    {
        return [
            'number' => $basket->getNumber(),
            'status' => $basket->getStatus()->value,
            'test' => $basket->isTestMode(),
            'created' => $basket->getCreation()?->format('Y-m-d H:i'),
            'currency' => $basket->getCurrency(),
            'quantity' => $basket->getQuantity(),
            'total' => $basket->getTotal(),
            'shipping' => $basket->getShipping(),
            'discountAmount' => $basket->getDiscountAmount(),
            'payable' => $basket->getPayable(),
            'refundedAmount' => $basket->getRefundedAmount(),
            'toSend' => $this->toSend($basket),
            'archived' => $basket->getArchived()?->format('Y-m-d H:i'),
        ];
    }

    // The parcels a paid order still has to post, each flag read with its own date
    /** @return list<string> */
    private function toSend(Basket $basket): array
    {
        if (BasketStatus::Paid !== $basket->getStatus()) {
            return [];
        }
        $flags = $basket->getContentFlags();

        return array_keys(array_filter([
            'items' => 0 !== ($flags & Basket::CONTENT_FLAG_PHYSICAL) && null === $basket->getItemsShipped(),
            'counterparts' => 0 !== ($flags & Basket::CONTENT_FLAG_CF_SHIPPING) && null === $basket->getCounterpartsShipped(),
        ]));
    }

    // The lines as they were sold, the keys read one by one: whatever a provider keeps beside them, an engraving or a message, stays out
    /** @return list<array{kind: string, label: string, quantity: int, unitPrice: int, total: int, totalVat: int}> */
    private function lines(Basket $basket): array
    {
        $lines = [];
        foreach ($basket->getItems() as $kind => $itemsOfThisKind) {
            foreach ($itemsOfThisKind as $line) {
                $title = (string) ($line['item']['title'] ?? '');
                $parent = (string) $line['parent']['title'];
                $lines[] = [
                    'kind' => (string) $kind,
                    'label' => '' === $parent ? $title : $parent . ' (' . $title . ')',
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'unitPrice' => (int) ($line['item']['price'] ?? 0),
                    'total' => (int) ($line['total'] ?? 0),
                    'totalVat' => (int) $line['totalVat'],
                ];
            }
        }

        return $lines;
    }
}
