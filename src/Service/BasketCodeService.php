<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// Tells a promotional code from a gift card, the customer holding a code and not a category, and allows one per basket: stacking is a rule nobody wrote, written here the day it is wanted
class BasketCodeService
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly GiftCardRepository $giftCardRepository,
        private readonly TranslatorInterface $translator,
        private readonly PaymentTestModeInterface $testMode,
    ) {
    }

    /**
     * What a typed code is worth against a basket, or why it is worth nothing.
     *
     * Pure on purpose - it writes nothing and reads no basket - so BasketService can call it again on every change of the basket without anything to undo first.
     *
     * @param int $itemsTotal    the items alone, shipping excluded
     * @param int $giftCardTotal what of those items are gift cards, which a promotional code is never taken off
     *
     * @return array{kind: string|null, code: string|null, amount: int, error: string|null}
     */
    public function resolve(string $code, int $itemsTotal, int $giftCardTotal, int $shipping, string $currency): array
    {
        $code = mb_strtoupper(trim($code));

        if ('' === $code) {
            return $this->refused('error.code_unknown');
        }

        // A gift card is printed in groups of four and typed back the way it is read - "ABCD-EFGH-JKLM-NPQR", or with spaces - while what is stored carries no separator at all. The typed code is tried as it stands first, so a promotional code the shop wrote with a dash of its own is still found by its own spelling
        $candidates = [$code];
        $compact = (string) preg_replace('/[\s\-]+/u', '', $code);
        if ($compact !== $code && '' !== $compact) {
            $candidates[] = $compact;
        }

        foreach ($candidates as $candidate) {
            $discount = $this->discountRepository->findOneByCode($candidate);
            if (null !== $discount) {
                return $this->resolveDiscount($discount, $itemsTotal, $giftCardTotal, $currency);
            }

            $giftCard = $this->giftCardRepository->findOneByCode($candidate);
            if (null !== $giftCard) {
                return $this->resolveGiftCard($giftCard, $itemsTotal, $shipping, $currency);
            }
        }

        return $this->refused('error.code_unknown');
    }

    // The same reading against a basket, for whoever holds one rather than its figures
    public function resolveForBasket(string $code, Basket $basket, int $giftCardTotal = 0): array
    {
        return $this->resolve($code, (int) $basket->getTotal(), $giftCardTotal, (int) $basket->getShipping(), (string) $basket->getCurrency());
    }

    // Writes a resolution onto the basket, or clears it - the totals themselves are BasketService's, which is the only place that knows what a basket adds up to
    public function apply(Basket $basket, array $resolution): void
    {
        if (null === $resolution['kind']) {
            $this->clear($basket);

            return;
        }

        $basket
            ->setDiscountCode($resolution['code'])
            ->setDiscountKind($resolution['kind'])
            ->setDiscountAmount($resolution['amount'])
        ;
    }

    public function clear(Basket $basket): void
    {
        $basket
            ->setDiscountCode(null)
            ->setDiscountKind(null)
            ->setDiscountAmount(0)
        ;
    }

    /**
     * Spends what the settled order used, and says whether it could be spent.
     *
     * Called from BasketService::paid(), i.e. once and once only per order (see BasketRepository::claimPaid()), and never at validation: a basket abandoned at the payment page must burn neither a quota nor a balance.
     *
     * A refusal here means the code ran out between the last check and the payment, which is a window of seconds - the order is already paid for, so it is never undone, only reported.
     */
    public function redeem(Basket $basket): bool
    {
        $code = $basket->getDiscountCode();

        if (null === $code || $basket->getDiscountAmount() <= 0) {
            return true;
        }

        if (Basket::CODE_KIND_GIFT_CARD === $basket->getDiscountKind()) {
            $giftCard = $this->giftCardRepository->findOneByCode($code);

            return null !== $giftCard && $this->giftCardRepository->claimAmount($giftCard, $basket->getDiscountAmount());
        }

        $discount = $this->discountRepository->findOneByCode($code);

        return null !== $discount && $this->discountRepository->claimUse($discount);
    }

    /**
     * @return array{kind: string|null, code: string|null, amount: int, error: string|null}
     */
    private function resolveDiscount(Discount $discount, int $itemsTotal, int $giftCardTotal, string $currency): array
    {
        if (!$discount->isActive()) {
            return $this->refused('error.code_unknown');
        }

        $wrongMode = $this->wrongMode($discount->isTestMode());
        if (null !== $wrongMode) {
            return $wrongMode;
        }

        $now = new \DateTime();
        if ((null !== $discount->getValidFrom() && $discount->getValidFrom() > $now) || (null !== $discount->getValidUntil() && $discount->getValidUntil() < $now)) {
            return $this->refused('error.code_expired');
        }

        // Zero means as many as come; anything else is a quota the settled orders have already eaten into
        if ($discount->getMaxUses() > 0 && $discount->getUsedCount() >= $discount->getMaxUses()) {
            return $this->refused('error.code_exhausted');
        }

        // A code written in one currency says nothing about a basket priced in another, and a converted amount would be a rate nobody wrote
        if (null !== $discount->getCurrency() && $discount->getCurrency() !== mb_strtoupper($currency)) {
            return $this->refused('error.code_unknown');
        }

        // The gift cards of the basket are not what a promotion is written against: money bought in advance, discounted, is money the shop gives away twice
        $base = $this->discountBase($itemsTotal, $giftCardTotal);

        // Compared before anything is taken off, like the free-shipping threshold: a minimum met by the basket the customer built is met, whatever the code then removes
        if ($base < $discount->getMinimumTotal()) {
            return $this->refused('error.code_minimum', ['%amount%' => number_format($discount->getMinimumTotal() / 100, 2, ',', ' ')]);
        }

        if ($base <= 0) {
            return $this->refused('error.code_nothing_to_discount');
        }

        $amount = Discount::KIND_PERCENTAGE === $discount->getKind()
            ? intdiv($base * $discount->getValue(), 100)
            : $discount->getValue();

        return [
            'kind' => Basket::CODE_KIND_DISCOUNT,
            'code' => $discount->getCode(),
            // Never more than what it is taken off: a 20 € code on a 15 € basket takes 15, and the shop owes nobody the difference
            'amount' => min($amount, $base),
            'error' => null,
        ];
    }

    /**
     * @return array{kind: string|null, code: string|null, amount: int, error: string|null}
     */
    private function resolveGiftCard(GiftCard $giftCard, int $itemsTotal, int $shipping, string $currency): array
    {
        if (!$giftCard->isActive()) {
            return $this->refused('error.code_unknown');
        }

        $wrongMode = $this->wrongMode($giftCard->isTestMode());
        if (null !== $wrongMode) {
            return $wrongMode;
        }

        if (null !== $giftCard->getValidUntil() && $giftCard->getValidUntil() < new \DateTime()) {
            return $this->refused('error.code_expired');
        }

        if ($giftCard->getCurrency() !== mb_strtoupper($currency)) {
            return $this->refused('error.code_wrong_currency');
        }

        if ($giftCard->getBalance() <= 0) {
            return $this->refused('error.code_no_balance');
        }

        // A card is money and not a promotion, so it pays the shipping too - what it cannot do is pay more than the order costs, the rest staying on it for the next one
        $payable = max(0, $itemsTotal + $shipping);

        if ($payable <= 0) {
            return $this->refused('error.code_nothing_to_discount');
        }

        return [
            'kind' => Basket::CODE_KIND_GIFT_CARD,
            'code' => $giftCard->getCode(),
            'amount' => min($giftCard->getBalance(), $payable),
            'error' => null,
        ];
    }

    /**
     * Refuses a code born in the other mode, the way a provider refuses a test key in production.
     *
     * A live shop says nothing more than "no such code": telling a stranger that a code exists but is a test one says one thing too many. A shop in test mode is being rehearsed by its own owner, so it gets told exactly what happened.
     *
     * @return array{kind: null, code: null, amount: 0, error: string}|null null when the code belongs to the mode the shop is charging in
     */
    private function wrongMode(bool $codeIsTest): ?array
    {
        $shopIsTest = $this->testMode->isEnabled();

        if ($codeIsTest === $shopIsTest) {
            return null;
        }

        return $this->refused($shopIsTest ? 'error.code_live_in_test_mode' : 'error.code_unknown');
    }

    // See CONTENT_FLAG_GIFT_CARD: whoever sells a card says so on its basket entry, and this is the whole of what that flag is read for
    private function discountBase(int $itemsTotal, int $giftCardTotal): int
    {
        return max(0, $itemsTotal - max(0, $giftCardTotal));
    }

    /**
     * @return array{kind: null, code: null, amount: 0, error: string}
     */
    private function refused(string $message, array $parameters = []): array
    {
        return ['kind' => null, 'code' => null, 'amount' => 0, 'error' => $this->translator->trans($message, $parameters, 'payment')];
    }
}
