<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\MessageHandler;

use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Message\GiftCardRecipientMessage;
use c975L\PaymentBundle\Repository\BasketRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GiftCardRecipientMessageHandler
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketEmailSender $basketEmailSender,
    ) {
    }

    public function __invoke(GiftCardRecipientMessage $message): void
    {
        $basket = $this->basketRepository->find($message->getBasketId());

        // Re-read here and not trusted from the dispatch: the buyer may have been given no address to write to at all, in which case they forward the link themselves and nothing is owed to anybody
        if (null === $basket || !$basket->hasGiftCardRecipient()) {
            return;
        }

        // Thrown so Messenger retries, same as the buyer's own confirmation: a card nobody was told about is a present that never arrives
        if (!$this->basketEmailSender->send($basket, 'label.gift_card_recipient', 'gift_card_recipient', [], $basket->getGiftCardRecipientEmail())) {
            throw new \RuntimeException(sprintf('Could not tell the recipient about the gift cards of basket "%s": %s', $basket->getNumber(), $this->basketEmailSender->getLastError() ?? 'unknown error'));
        }
    }
}
