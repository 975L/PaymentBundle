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
use c975L\ConfigBundle\Mcp\McpToolProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\PaymentRepository;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

// The payments and discount codes on the site's MCP endpoint, for an account administering them on the back office: read only, no refund, capture or code ever written, no customer identity nor address given
class PaymentMcpProvider implements McpToolProviderInterface
{
    // Rows a list gives at most
    public const int LIMIT = 50;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly DiscountRepository $discountRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
    ) {
    }

    // Key of the payments for the agent tokens
    public function getMcpKey(): string
    {
        return 'payment';
    }

    // How the payments' tools go together
    public function getMcpInstructions(): string
    {
        return 'Payments and discount codes are read only, amounts in cents of their currency. Refunds, captures and codes are handled by a human from the back office.';
    }

    // The tools of the payments, none of them writing
    public function getMcpTools(): iterable
    {
        yield new McpTool('payment_list', 'Payments', 'The latest payments, gone through or not, with amount, provider, date and the status of the order they pay.', $this->list(...));
        yield new McpTool('payment_get', 'Payment', 'A payment with its provider transaction and its order: totals, discount, refund, invoice, country.', $this->get(...));
        yield new McpTool('payment_discount_list', 'Discount codes', 'The discount codes whose code holds the search words, every code without them, with their value, validity and uses.', $this->discounts(...));
    }

    // The latest payments, only those gone through or not when asked
    /**
     * @param ?bool $finished true for the payments gone through, false for those that did not, none for both
     *
     * @return list<array{id: ?int, amount: ?int, currency: ?string, finished: bool, gateway: ?string, method: ?string, created: ?string, order: ?string, status: ?string, test: bool}>
     */
    public function list(?bool $finished = null): array
    {
        $this->guard();
        $builder = $this->paymentRepository->createQueryBuilder('p')
            ->leftJoin('p.basket', 'b')
            ->addSelect('b')
            ->orderBy('p.creation', \SortDirection::Descending)
            ->setMaxResults(self::LIMIT);
        if (null !== $finished) {
            $builder->andWhere('p.isFinished = :finished')->setParameter('finished', $finished);
        }

        return array_map($this->summary(...), $builder->getQuery()->getResult());
    }

    // A payment with its provider transaction and the figures of its order
    /**
     * @param int $payment id of the payment
     *
     * @return array{id: ?int, amount: ?int, currency: ?string, finished: bool, gateway: ?string, method: ?string, created: ?string, order: ?string, status: ?string, test: bool, transaction: ?string, modified: ?string, total: ?int, shipping: ?int, discountCode: ?string, discountAmount: ?int, refundedAmount: ?int, refunded: ?string, invoice: ?string, country: ?string}
     */
    public function get(int $payment): array
    {
        $this->guard();
        $found = $this->paymentRepository->find($payment);
        if (!$found instanceof Payment) {
            throw new ToolCallException(sprintf('No payment %d.', $payment));
        }
        $basket = $found->getBasket();

        return [
            ...$this->summary($found),
            'transaction' => $found->getTransactionId(),
            'modified' => $found->getModification()?->format('Y-m-d H:i'),
            'total' => $basket?->getTotal(),
            'shipping' => $basket?->getShipping(),
            'discountCode' => $basket?->getDiscountCode(),
            'discountAmount' => $basket?->getDiscountAmount(),
            'refundedAmount' => $basket?->getRefundedAmount(),
            'refunded' => $basket?->getRefundedAt()?->format('Y-m-d H:i'),
            'invoice' => $basket?->getInvoiceNumber(),
            'country' => $basket?->getCountry(),
        ];
    }

    // The discount codes holding the words, every one without them, the latest first
    /**
     * @param ?string $search words looked for in the codes
     *
     * @return list<array{id: ?int, code: ?string, kind: string, value: int, currency: ?string, minimumTotal: int, validFrom: ?string, validUntil: ?string, maxUses: int, usedCount: int, active: bool, test: bool}>
     */
    public function discounts(?string $search = null): array
    {
        $this->guard();
        $builder = $this->discountRepository->createQueryBuilder('d')
            ->orderBy('d.creation', \SortDirection::Descending)
            ->setMaxResults(self::LIMIT);
        if ('' !== trim((string) $search)) {
            $builder->andWhere('d.code LIKE :words')->setParameter('words', '%' . addcslashes(mb_strtoupper(trim((string) $search)), '%_\\') . '%');
        }

        return array_map($this->discount(...), $builder->getQuery()->getResult());
    }

    // Payments and codes are the admin's business, as on the back office
    private function guard(): void
    {
        if (!$this->security->isGranted((string) $this->configService->get('site-role-admin'))) {
            throw new ToolCallException('This account may not read the payments.');
        }
    }

    // A payment in a list, its order named by its number, never by its customer
    /** @return array{id: ?int, amount: ?int, currency: ?string, finished: bool, gateway: ?string, method: ?string, created: ?string, order: ?string, status: ?string, test: bool} */
    private function summary(Payment $payment): array
    {
        $basket = $payment->getBasket();

        return [
            'id' => $payment->getId(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'finished' => true === $payment->isFinished(),
            'gateway' => $payment->getGateway(),
            'method' => $payment->getPaymentMethod(),
            'created' => $payment->getCreation()?->format('Y-m-d H:i'),
            'order' => $basket?->getNumber(),
            'status' => $basket?->getStatus()->value,
            'test' => true === $basket?->isTestMode(),
        ];
    }

    // A discount code in a list, a value in percent or in cents after its kind, no maximum of uses for zero
    /** @return array{id: ?int, code: ?string, kind: string, value: int, currency: ?string, minimumTotal: int, validFrom: ?string, validUntil: ?string, maxUses: int, usedCount: int, active: bool, test: bool} */
    private function discount(Discount $discount): array
    {
        return [
            'id' => $discount->getId(),
            'code' => $discount->getCode(),
            'kind' => $discount->getKind(),
            'value' => $discount->getValue(),
            'currency' => $discount->getCurrency(),
            'minimumTotal' => $discount->getMinimumTotal(),
            'validFrom' => $discount->getValidFrom()?->format('Y-m-d H:i'),
            'validUntil' => $discount->getValidUntil()?->format('Y-m-d H:i'),
            'maxUses' => $discount->getMaxUses(),
            'usedCount' => $discount->getUsedCount(),
            'active' => $discount->isActive(),
            'test' => $discount->isTestMode(),
        ];
    }
}
