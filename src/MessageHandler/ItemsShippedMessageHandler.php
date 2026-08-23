<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\MessageHandler;

use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Message\ItemsShippedMessage;
use c975L\PaymentBundle\Repository\BasketRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ItemsShippedMessageHandler
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketEmailSender $basketEmailSender,
    ) {
    }

    public function __invoke(ItemsShippedMessage $message): void
    {
        $basket = $this->basketRepository->find($message->getBasketId());
        if (!$basket) {
            return;
        }

        // Products and crowdfunding counterparts ship separately and say so in their own words
        $isProduct = 'product' === $message->getType();
        $subjectKey = $isProduct ? 'label.items_shipped' : 'label.counterparts_shipped';
        $template = $isProduct ? 'items_shipped' : 'counterparts_shipped';

        // Throws on failure so Messenger retries it, a shipping notice lost silently being one the buyer never gets
        if (!$this->basketEmailSender->send($basket, $subjectKey, $template)) {
            throw new \RuntimeException(sprintf('Could not send the shipping notice of basket "%s": %s', $basket->getNumber(), $this->basketEmailSender->getLastError() ?? 'unknown error'));
        }
    }
}
