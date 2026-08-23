<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the one input of the basket page makes of a code, which is the whole of what the customer is charged
class BasketCodeServiceTest extends TestCase
{
    public function testACodeNobodyWroteIsRefused(): void
    {
        $resolution = $this->service()->resolve('NOEL', 10000, 0, 500, 'EUR');

        $this->assertNull($resolution['kind']);
        $this->assertSame(0, $resolution['amount']);
        $this->assertSame('error.code_unknown', $resolution['error']);
    }

    public function testAPercentageIsTakenOffTheItemsAlone(): void
    {
        $resolution = $this->service(discount: $this->discount())->resolve('NOEL', 10000, 0, 500, 'EUR');

        $this->assertSame(Basket::CODE_KIND_DISCOUNT, $resolution['kind']);
        $this->assertSame(1000, $resolution['amount']);
    }

    // A promotion on money bought in advance is money the shop gives away twice
    public function testAGiftCardInTheBasketIsNotWhatAPromotionIsTakenOff(): void
    {
        $resolution = $this->service(discount: $this->discount())->resolve('NOEL', 10000, 6000, 500, 'EUR');

        $this->assertSame(400, $resolution['amount']);
    }

    // A fixed amount larger than the basket takes the basket, and the shop owes nobody the difference
    public function testAFixedAmountNeverGoesBelowZero(): void
    {
        $discount = $this->discount()->setKind(Discount::KIND_AMOUNT)->setValue(5000);

        $this->assertSame(1500, $this->service(discount: $discount)->resolve('NOEL', 1500, 0, 500, 'EUR')['amount']);
    }

    // Compared against what the customer put in the basket, exactly like the free-shipping threshold
    public function testTheMinimumIsReadOnTheItemsBeforeAnythingIsTakenOff(): void
    {
        $discount = $this->discount()->setMinimumTotal(4000);
        $service = $this->service(discount: $discount);

        $this->assertSame('error.code_minimum', $service->resolve('NOEL', 3999, 0, 0, 'EUR')['error']);
        $this->assertNull($service->resolve('NOEL', 4000, 0, 0, 'EUR')['error']);
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $discount = $this->discount()->setValidUntil(new \DateTime('-1 day'));

        $this->assertSame('error.code_expired', $this->service(discount: $discount)->resolve('NOEL', 10000, 0, 0, 'EUR')['error']);
    }

    public function testACodeThatHasServedItsQuotaIsRefused(): void
    {
        $discount = $this->discount()->setMaxUses(10)->setUsedCount(10);

        $this->assertSame('error.code_exhausted', $this->service(discount: $discount)->resolve('NOEL', 10000, 0, 0, 'EUR')['error']);
    }

    public function testAnInactiveCodeReadsAsNoCodeAtAll(): void
    {
        $discount = $this->discount()->setActive(false);

        $this->assertSame('error.code_unknown', $this->service(discount: $discount)->resolve('NOEL', 10000, 0, 0, 'EUR')['error']);
    }

    // A card is money, so it pays the shipping too - which a promotion never does
    public function testAGiftCardPaysTheShippingAsWellAsTheItems(): void
    {
        $resolution = $this->service(giftCard: $this->giftCard(10000))->resolve('AAAABBBBCCCCDDDD', 4000, 0, 500, 'EUR');

        $this->assertSame(Basket::CODE_KIND_GIFT_CARD, $resolution['kind']);
        $this->assertSame(4500, $resolution['amount']);
    }

    // What is left stays on it for the next order rather than being lost
    public function testACardWorthMoreThanTheOrderOnlyGivesWhatTheOrderCosts(): void
    {
        $this->assertSame(2000, $this->service(giftCard: $this->giftCard(10000))->resolve('AAAABBBBCCCCDDDD', 2000, 0, 0, 'EUR')['amount']);
    }

    public function testACardShorterThanTheOrderGivesAllItHas(): void
    {
        $this->assertSame(1500, $this->service(giftCard: $this->giftCard(1500))->resolve('AAAABBBBCCCCDDDD', 9000, 0, 0, 'EUR')['amount']);
    }

    public function testAnEmptyCardIsRefused(): void
    {
        $this->assertSame('error.code_no_balance', $this->service(giftCard: $this->giftCard(0))->resolve('AAAABBBBCCCCDDDD', 9000, 0, 0, 'EUR')['error']);
    }

    public function testACardInAnotherCurrencyIsRefused(): void
    {
        $this->assertSame('error.code_wrong_currency', $this->service(giftCard: $this->giftCard(5000))->resolve('AAAABBBBCCCCDDDD', 9000, 0, 0, 'CHF')['error']);
    }

    // Typed off a flyer however the customer likes
    public function testTheCodeIsReadWhateverCaseItIsTypedIn(): void
    {
        $this->assertSame(1000, $this->service(discount: $this->discount())->resolve('  noel  ', 10000, 0, 0, 'EUR')['amount']);
    }

    public function testApplyingAResolutionWritesTheThreeColumnsAndClearingTakesThemBackOff(): void
    {
        $service = $this->service(discount: $this->discount());
        $basket = new Basket()->setTotal(10000)->setShipping(500)->setCurrency('EUR');

        $service->apply($basket, $service->resolve('NOEL', 10000, 0, 500, 'EUR'));

        $this->assertSame('NOEL', $basket->getDiscountCode());
        $this->assertSame(Basket::CODE_KIND_DISCOUNT, $basket->getDiscountKind());
        $this->assertSame(1000, $basket->getDiscountAmount());
        $this->assertSame(9500, $basket->getPayable());

        $service->clear($basket);

        $this->assertNull($basket->getDiscountCode());
        $this->assertSame(0, $basket->getDiscountAmount());
        $this->assertSame(10500, $basket->getPayable());
    }

    // A card covering everything leaves nothing for a gateway to do, which is the free path the checkout already knew
    public function testACodeCoveringTheWholeOrderLeavesNothingToPay(): void
    {
        $service = $this->service(giftCard: $this->giftCard(50000));
        $basket = new Basket()->setTotal(2000)->setShipping(500)->setCurrency('EUR');

        $service->apply($basket, $service->resolveForBasket('AAAABBBBCCCCDDDD', $basket));

        $this->assertSame(0, $basket->getPayable());
    }

    public function testABasketCarryingNoCodeRedeemsNothing(): void
    {
        $this->assertTrue($this->service()->redeem(new Basket()));
    }

    // A rehearsal must not leave spendable money behind: a card issued in test mode is refused once the shop charges for real
    public function testACodeBornInTestModeIsRefusedByALiveShop(): void
    {
        $giftCard = $this->giftCard(5000)->setTestMode(true);

        $this->assertSame('error.code_unknown', $this->service(giftCard: $giftCard)->resolve('AAAABBBBCCCCDDDD', 9000, 0, 0, 'EUR')['error']);
    }

    // And the other way round, so a real card is never spent on an order nobody is charged for
    public function testALiveCodeIsRefusedByAShopInTestMode(): void
    {
        $service = $this->service(discount: $this->discount(), shopInTestMode: true);

        $this->assertSame('error.code_live_in_test_mode', $service->resolve('NOEL', 10000, 0, 0, 'EUR')['error']);
    }

    public function testACodeBornInTestModeWorksWhileTheShopIsBeingRehearsed(): void
    {
        $service = $this->service(discount: $this->discount()->setTestMode(true), shopInTestMode: true);

        $this->assertSame(1000, $service->resolve('NOEL', 10000, 0, 0, 'EUR')['amount']);
    }

    // A card is printed in groups of four and read out loud that way, so it comes back typed with whatever separator the customer put between them
    public function testAGiftCardIsFoundThroughTheSeparatorsItIsReadWith(): void
    {
        $service = $this->service(giftCard: $this->giftCard(3000));

        foreach (['AAAA-BBBB-CCCC-DDDD', 'aaaa bbbb cccc dddd', ' AAAA - BBBB CCCC-DDDD '] as $typed) {
            $resolution = $service->resolve($typed, 10000, 0, 500, 'EUR');

            $this->assertSame(Basket::CODE_KIND_GIFT_CARD, $resolution['kind'], $typed);
            $this->assertSame(3000, $resolution['amount'], $typed);
        }
    }

    // The typed code is tried as it stands first: a promotional code the shop wrote with a dash of its own is found by its own spelling, not by what is left of it
    public function testAPromotionalCodeKeepsItsOwnDashes(): void
    {
        $discount = $this->discount()->setCode('NOEL-2026');

        $this->assertSame(Basket::CODE_KIND_DISCOUNT, $this->service(discount: $discount)->resolve('noel-2026', 10000, 0, 500, 'EUR')['kind']);
    }

    private function discount(): Discount
    {
        return new Discount()
            ->setCode('NOEL')
            ->setKind(Discount::KIND_PERCENTAGE)
            ->setValue(10)
        ;
    }

    private function giftCard(int $balance): GiftCard
    {
        return new GiftCard()
            ->setCode('AAAABBBBCCCCDDDD')
            ->setInitialAmount($balance)
            ->setBalance($balance)
            ->setCurrency('EUR')
        ;
    }

    private function service(?Discount $discount = null, ?GiftCard $giftCard = null, bool $shopInTestMode = false): BasketCodeService
    {
        $discountRepository = $this->createStub(DiscountRepository::class);
        $discountRepository->method('findOneByCode')->willReturnCallback(
            static fn (string $code): ?Discount => null !== $discount && mb_strtoupper(trim($code)) === $discount->getCode() ? $discount : null
        );

        $giftCardRepository = $this->createStub(GiftCardRepository::class);
        $giftCardRepository->method('findOneByCode')->willReturnCallback(
            static fn (string $code): ?GiftCard => null !== $giftCard && mb_strtoupper(trim($code)) === $giftCard->getCode() ? $giftCard : null
        );

        // The error is the key itself, so a test says which refusal happened rather than matching a sentence
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($shopInTestMode);

        return new BasketCodeService($discountRepository, $giftCardRepository, $translator, $testMode);
    }
}
