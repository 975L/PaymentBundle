<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\MessageHandler;

use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Message\GiftCardRecipientMessage;
use c975L\PaymentBundle\MessageHandler\GiftCardRecipientMessageHandler;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;

// The one email this bundle sends to somebody who ordered nothing: whoever a card was bought for
class GiftCardRecipientMessageHandlerTest extends TestCase
{
    public function testItWritesToTheRecipientAndNotToTheBuyer(): void
    {
        $sender = $this->createMock(BasketEmailSender::class);
        $sender->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Basket::class), 'label.gift_card_recipient', 'gift_card_recipient', [], 'grandmother@example.test')
            ->willReturn(true);

        new GiftCardRecipientMessageHandler($this->repository($this->basket()), $sender)(new GiftCardRecipientMessage(1));
    }

    // No address given: the buyer got the links in their own confirmation and forwards them, so nobody is owed anything here
    public function testAnOrderWithNoAddressToWriteToSendsNothing(): void
    {
        $sender = $this->createMock(BasketEmailSender::class);
        $sender->expects($this->never())->method('send');

        $basket = $this->basket()->setGiftCardRecipientEmail(null);

        new GiftCardRecipientMessageHandler($this->repository($basket), $sender)(new GiftCardRecipientMessage(1));
    }

    // An address left on an order that ended up carrying no card at all: there is nothing to tell anybody about
    public function testAnOrderCarryingNoCardSendsNothingEvenWithAnAddress(): void
    {
        $sender = $this->createMock(BasketEmailSender::class);
        $sender->expects($this->never())->method('send');

        $basket = $this->basket()->setContentFlags(Basket::CONTENT_FLAG_PHYSICAL);

        new GiftCardRecipientMessageHandler($this->repository($basket), $sender)(new GiftCardRecipientMessage(1));
    }

    // A card that never arrives is a present that never arrives, so the send is retried like the buyer's own confirmation
    public function testAFailedSendIsThrownSoMessengerRetriesIt(): void
    {
        $sender = $this->createStub(BasketEmailSender::class);
        $sender->method('send')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ORDER-1/');

        new GiftCardRecipientMessageHandler($this->repository($this->basket()), $sender)(new GiftCardRecipientMessage(1));
    }

    public function testAVanishedBasketIsNotRetried(): void
    {
        $sender = $this->createMock(BasketEmailSender::class);
        $sender->expects($this->never())->method('send');

        new GiftCardRecipientMessageHandler($this->repository(null), $sender)(new GiftCardRecipientMessage(1));
    }

    private function basket(): Basket
    {
        return new Basket()
            ->setNumber('ORDER-1')
            ->setEmail('buyer@example.test')
            ->setContentFlags(Basket::CONTENT_FLAG_GIFT_CARD)
            ->setGiftCardRecipientEmail('grandmother@example.test');
    }

    private function repository(?Basket $basket): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn($basket);

        return $repository;
    }
}
