<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\GiftCardDesign;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Service\GiftCardService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

// The cards a paid order buys - money, and so issued by a purchase rather than typed in the back office
class GiftCardServiceTest extends TestCase
{
    public function testACardIsIssuedAtItsFullValueAndTiedToTheOrderThatBoughtIt(): void
    {
        $basket = new Basket()->setNumber('20260822-0001');

        $giftCard = $this->service()->issue(3000, 'EUR', $basket);

        $this->assertSame(3000, $giftCard->getInitialAmount());
        $this->assertSame(3000, $giftCard->getBalance());
        $this->assertSame('EUR', $giftCard->getCurrency());
        $this->assertSame('20260822-0001', $giftCard->getIssuedByBasket());
        $this->assertTrue($giftCard->isActive());
    }

    // Not flushed here: this runs inside the delivery of the order, which flushes once for everything it wrote
    public function testTheCardIsPersistedButNotFlushed(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $this->service(entityManager: $entityManager)->issue(3000, 'EUR');
    }

    // A bearer instrument: sixteen characters out of an alphabet with no I, O, 0 or 1, which are the four a customer gets wrong
    public function testTheCodeIsLongRandomAndFreeOfTheCharactersOneMisreads(): void
    {
        $service = $this->service();
        $codes = [];

        for ($i = 0; $i < 50; ++$i) {
            $code = $service->generateCode();
            $this->assertSame(16, \strlen($code));
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{16}$/', $code);
            $codes[] = $code;
        }

        $this->assertCount(50, array_unique($codes));
    }

    // A collision would hand a second customer the first one's balance, so a taken code is drawn again rather than trusted
    public function testACodeAlreadyHeldByARowIsDrawnAgain(): void
    {
        $seen = [];
        $repository = $this->createStub(GiftCardRepository::class);
        $repository->method('findOneByCode')->willReturnCallback(
            static function (string $code) use (&$seen): ?GiftCard {
                // The first draw is refused whatever it is, the second accepted
                if ([] === $seen) {
                    $seen[] = $code;

                    return new GiftCard()->setCode($code);
                }

                return null;
            }
        );

        $this->assertNotContains($this->service(repository: $repository)->generateCode(), $seen);
    }

    public function testAnOrderWithoutANumberHasNoCardToShow(): void
    {
        $this->assertSame([], $this->service()->findIssuedBy(new Basket()));
    }

    // Stamped at birth: a card bought during a rehearsal is not money, and BasketCodeService refuses it once the shop charges for real
    public function testACardIssuedWhileTheShopIsBeingRehearsedIsMarkedAsSuch(): void
    {
        $this->assertTrue($this->service(shopInTestMode: true)->issue(3000, 'EUR')->isTestMode());
        $this->assertFalse($this->service()->issue(3000, 'EUR')->isTestMode());
    }

    // A year from the day it is bought unless the shop set another figure - the date the card itself has to print
    public function testACardExpiresAfterTheDurationTheShopSet(): void
    {
        $giftCard = $this->service()->issue(3000, 'EUR');

        $this->assertEquals(new \DateTime('+12 months')->format('Y-m-d'), $giftCard->getValidUntil()?->format('Y-m-d'));
    }

    // Zero months is what a shop that wants no expiry sets
    public function testAShopMaySetNoExpiryAtAll(): void
    {
        $this->assertNull($this->service(validityMonths: 0)->issue(3000, 'EUR')->getValidUntil());
    }

    // A date stated by the caller wins over the setting: the admin issuing a card by hand may say when it runs out
    public function testAStatedDateWinsOverTheSetting(): void
    {
        $stated = new \DateTime('2027-06-30');

        $this->assertSame($stated, $this->service()->issue(3000, 'EUR', null, $stated)->getValidUntil());
    }

    // The visual is copied onto the card and not pointed at: a design withdrawn from sale next month must not blank a card somebody still holds
    public function testTheVisualTheCardWasSoldWithIsCopiedOntoIt(): void
    {
        $giftCard = $this->service()->issue(3000, 'EUR', null, null, new GiftCardDesign('medias/shop/noel.webp', 'Bon cadeau', false));

        $this->assertSame('medias/shop/noel.webp', $giftCard->getDesignImage());
        $this->assertSame('Bon cadeau', $giftCard->getDesignText());
        $this->assertFalse($giftCard->hasScratch());
    }

    // A card issued by hand carries no visual at all, and keeps the panel a card is sold with by default
    public function testACardIssuedWithoutADesignKeepsThePanel(): void
    {
        $giftCard = $this->service()->issue(3000, 'EUR');

        $this->assertNull($giftCard->getDesignImage());
        $this->assertNull($giftCard->getDesignText());
        $this->assertTrue($giftCard->hasScratch());
    }

    // The address whoever the card is for opens it at, which is never its code: a url travels through histories, referrers and chat servers
    public function testEachCardIsGivenAnAddressOfItsOwnThatIsNotItsCode(): void
    {
        $service = $this->service();
        $tokens = [];

        for ($i = 0; $i < 20; ++$i) {
            $giftCard = $service->issue(3000, 'EUR');
            $token = (string) $giftCard->getShareToken();

            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
            $this->assertNotSame($giftCard->getCode(), $token);
            $tokens[] = $token;
        }

        $this->assertCount(20, array_unique($tokens));
    }

    // Same guard as the code's: an address already held would show one holder the other's card
    public function testAnAddressAlreadyHeldByARowIsDrawnAgain(): void
    {
        $seen = [];
        $repository = $this->createStub(GiftCardRepository::class);
        $repository->method('findOneByShareToken')->willReturnCallback(
            static function (string $shareToken) use (&$seen): ?GiftCard {
                if ([] === $seen) {
                    $seen[] = $shareToken;

                    return new GiftCard()->setShareToken($shareToken);
                }

                return null;
            }
        );

        $this->assertNotContains($this->service(repository: $repository)->generateShareToken(), $seen);
    }

    private function service(?GiftCardRepository $repository = null, ?EntityManagerInterface $entityManager = null, bool $shopInTestMode = false, int $validityMonths = 12): GiftCardService
    {
        $repository ??= $this->createStub(GiftCardRepository::class);

        $testMode = $this->createStub(PaymentTestModeInterface::class);
        $testMode->method('isEnabled')->willReturn($shopInTestMode);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($validityMonths);

        return new GiftCardService($repository, $entityManager ?? $this->createStub(EntityManagerInterface::class), $testMode, $configService);
    }
}
