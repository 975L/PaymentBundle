<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\MessageHandler;

use c975L\PaymentBundle\Email\BasketEmailFactory;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\UiBundle\Service\EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ConfirmOrderMessageHandler
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketEmailFactory $basketEmailFactory,
        private readonly EmailService $emailService,
    ) {
    }

    public function __invoke(ConfirmOrderMessage $message): void
    {
        $basket = $this->basketRepository->find($message->getBasketId());
        if (!$basket) {
            return;
        }

        // Sends the email, throwing on failure so Messenger retries it: EmailService reports a failed send as false, and an order confirmation lost silently is one the buyer never gets
        $request = $this->basketEmailFactory->create($basket, 'label.confirm_order', 'confirm_order');
        if (!$this->emailService->send($request)) {
            throw new \RuntimeException(sprintf('Could not send the order confirmation of basket "%s": %s', $basket->getNumber(), $this->emailService->getLastError() ?? 'unknown error'));
        }
    }
}
