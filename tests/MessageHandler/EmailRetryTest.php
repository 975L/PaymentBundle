<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\MessageHandler;

use c975L\PaymentBundle\Email\BasketEmailFactory;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Message\ItemsShippedMessage;
use c975L\PaymentBundle\MessageHandler\ConfirmOrderMessageHandler;
use c975L\PaymentBundle\MessageHandler\ItemsShippedMessageHandler;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use PHPUnit\Framework\TestCase;

// UiBundle's EmailService reports a failed send as false rather than throwing, which suits a controller but would drop an order email in an async handler - both handlers must turn that false into an exception so Messenger retries
class EmailRetryTest extends TestCase
{
    public function testAFailedOrderConfirmationIsThrownSoMessengerRetriesIt(): void
    {
        $handler = new ConfirmOrderMessageHandler($this->repository(), $this->factory(), $this->emailService(false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ORDER-1/');

        $handler(new ConfirmOrderMessage(1));
    }

    public function testAFailedShippingNoticeIsThrownSoMessengerRetriesIt(): void
    {
        $handler = new ItemsShippedMessageHandler($this->repository(), $this->factory(), $this->emailService(false));

        $this->expectException(\RuntimeException::class);

        $handler(new ItemsShippedMessage(1, 'product'));
    }

    public function testASentEmailPassesQuietly(): void
    {
        $handler = new ConfirmOrderMessageHandler($this->repository(), $this->factory(), $this->emailService(true));

        $handler(new ConfirmOrderMessage(1));

        $this->expectNotToPerformAssertions();
    }

    // A basket deleted between the dispatch and the handling is not an error, and must not be retried for ever
    public function testAVanishedBasketIsNotRetried(): void
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn(null);
        $handler = new ConfirmOrderMessageHandler($repository, $this->factory(), $this->emailService(false));

        $handler(new ConfirmOrderMessage(1));

        $this->expectNotToPerformAssertions();
    }

    private function emailService(bool $sent): EmailService
    {
        $emailService = $this->createStub(EmailService::class);
        $emailService->method('send')->willReturn($sent);
        $emailService->method('getLastError')->willReturn('mailer refused the message');

        return $emailService;
    }

    private function repository(): BasketRepository
    {
        $basket = new Basket();
        $basket->setNumber('ORDER-1');
        $basket->setEmail('buyer@example.test');

        $repository = $this->createStub(BasketRepository::class);
        $repository->method('find')->willReturn($basket);

        return $repository;
    }

    // EmailSendRequest is final, so the stub is handed a real one rather than left to generate a double of it
    private function factory(): BasketEmailFactory
    {
        $factory = $this->createStub(BasketEmailFactory::class);
        $factory->method('create')->willReturn(new EmailSendRequest(
            subject: 'Shop - order',
            context: [],
            template: '@c975LPayment/emails/confirm_order.html.twig',
            to: 'buyer@example.test',
            wrapLayout: true,
        ));

        return $factory;
    }
}
