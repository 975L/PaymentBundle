<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\GiftCardDesign;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use Doctrine\ORM\EntityManagerInterface;

// Issues the cards a paid order bought. Called by whichever bundle sells them (see ShopBundle's ProductBasketItemProvider::onBasketPaid()): that bundle knows which of its items is a gift card and what it is worth, this one owns the money
class GiftCardService
{
    // No I, O, 0, 1: a code is read off a screen and typed on another, and those four are what a customer gets wrong. What is left is 32 characters, so each one carries 5 bits
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // Sixteen of them, i.e. 80 bits: this is a bearer instrument, and anybody able to guess one spends somebody else's money. Printed in groups of four, which is how a card is read out loud
    private const int LENGTH = 16;

    public function __construct(
        private readonly GiftCardRepository $giftCardRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentTestModeInterface $testMode,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    /**
     * Creates a card the order that paid for it is worth, and hands it back so the caller can say so to the customer.
     *
     * Not flushed here: this runs inside BasketService::paid(), which flushes once for everything the delivery wrote - a card persisted on its own would survive a later failure of that same delivery.
     */
    public function issue(int $amount, string $currency, ?Basket $basket = null, ?\DateTimeInterface $validUntil = null, ?GiftCardDesign $design = null): GiftCard
    {
        $giftCard = new GiftCard()
            ->setCode($this->generateCode())
            ->setShareToken($this->generateShareToken())
            ->setInitialAmount($amount)
            ->setBalance($amount)
            ->setCurrency($currency)
            ->setValidUntil($validUntil ?? $this->defaultValidUntil())
            ->setIssuedByBasket($basket?->getNumber())
            // Copied and not pointed at, so the card goes on being the object it was sold as once the catalogue has moved on
            ->setDesignImage($design?->image)
            ->setDesignText($design?->text)
            ->setScratch($design->scratch ?? true)
            // Stamped at birth and never afterwards: a rehearsal must not leave spendable money behind once the shop goes live
            ->setTestMode($this->testMode->isEnabled())
        ;

        $this->entityManager->persist($giftCard);

        return $giftCard;
    }

    /**
     * The cards one order paid for - what its confirmation email prints, and nothing else has to be carried across the checkout for it.
     *
     * @return GiftCard[]
     */
    public function findIssuedBy(Basket $basket): array
    {
        $number = $basket->getNumber();

        return null === $number ? [] : $this->giftCardRepository->findByBasketNumber($number);
    }

    /**
     * How long a card issued today stays spendable, counted from the day it is bought.
     *
     * A setting and not a rule written here: the law imposes no ceiling on a multi-purpose voucher, so how long a shop stands behind its own cards is the shop's decision. Zero means for good, which is what a shop that wants no expiry sets.
     *
     * The date is what the card has to print: a card whose expiry only lives in a database is a promise the holder cannot read.
     */
    private function defaultValidUntil(): ?\DateTimeInterface
    {
        $months = (int) $this->configService->get('payment-gift-card-validity');

        return $months > 0 ? new \DateTime('+' . $months . ' months') : null;
    }

    // A code no row already holds. Retried rather than trusted: the odds are negligible, but a collision would hand a second customer the first one's balance, and the unique index would only say so on the flush
    public function generateCode(): string
    {
        do {
            $code = $this->randomCode();
        } while (null !== $this->giftCardRepository->findOneByCode($code));

        return $code;
    }

    /**
     * The address whoever the card is for is given, which is not its code.
     *
     * Hexadecimal and not the code's own alphabet: nobody reads this one out loud, it is followed from a message, so
     * it is sized for what it has to withstand rather than for what a customer types back - 128 bits, against the 80
     * of a code meant to be read off a card.
     *
     * Twice the length of the basket's own share token (BasketService::generateSecurityToken()), and named the same
     * thing for the same reason - both are what a link handed to somebody else carries. That one opens an order
     * waiting to be paid, i.e. an afternoon; this one opens money that stands for a year.
     */
    public function generateShareToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (null !== $this->giftCardRepository->findOneByShareToken($token));

        return $token;
    }

    private function randomCode(): string
    {
        $code = '';
        $last = \strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
