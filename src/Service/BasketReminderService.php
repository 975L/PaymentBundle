<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Reminds the visitors who validated an order and never paid it, twice and then never again. Only they can be reminded: a basket still "new" carries no e-mail more often than not, and the one it does carry belongs to somebody who has told the shop nothing yet - which is what BasketRecoverySubscriber's cookie is for, a way back that asks no one anything
class BasketReminderService
{
    // The morning after, when an order left at the payment step is still what the customer meant to buy
    public const int FIRST_REMINDER_DAYS = 1;

    // A week later, the last one: past that the order is not coming back, and BasketRetentionService takes it away at thirty days
    public const int SECOND_REMINDER_DAYS = 7;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketEmailSender $basketEmailSender,
        private readonly BasketServiceInterface $basketService,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentTestModeInterface $testMode,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    // Sends whichever reminder each abandoned basket is due, and says how many went out
    public function send(): int
    {
        // A shop left in test mode is being tried out, not selling: its orders are rehearsals and their addresses are the shopkeeper's own
        if ($this->testMode->isEnabled()) {
            return 0;
        }

        $count = 0;
        $rounds = [
            self::FIRST_REMINDER_DAYS => 0,
            self::SECOND_REMINDER_DAYS => 1,
        ];

        foreach ($rounds as $days => $alreadySent) {
            foreach ($this->basketRepository->findToRemind($days, $alreadySent) as $basket) {
                if ($this->remind($basket, $alreadySent + 1)) {
                    ++$count;
                }
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    // Sends one reminder, and says whether it left
    private function remind(Basket $basket, int $rank): bool
    {
        // Two named e-mails rather than one carrying a rank: an EmailBlock has no conditional, so the second reminder is its own thing to compose and its own thing to read
        $sent = $this->basketEmailSender->send(
            $basket,
            'label.basket_reminder',
            1 === $rank ? 'basket_reminder_first' : 'basket_reminder_second',
            [
                'pay_url' => $this->payUrl($basket),
                'unsubscribe_url' => $this->unsubscribeUrl($basket),
                'days' => BasketRetentionService::ABANDONED_DAYS,
            ],
        );

        // Left for the next night rather than thrown: the count is only raised once the e-mail is really gone, so a shop whose mailer was down for an evening reminds its customers the day after instead of never
        if (!$sent) {
            $this->logger->error(sprintf('Could not remind basket "%s": %s', $basket->getNumber(), $this->basketEmailSender->getLastError() ?? 'unknown error'));

            return false;
        }

        $basket->setRemindersSent($rank);

        return true;
    }

    /**
     * The link that takes the customer straight back to their payment page.
     *
     * The shared-payment route rather than a new one of its own: it already opens the checkout of an order
     * waiting for its money without asking anything to be filled in again.
     */
    private function payUrl(Basket $basket): string
    {
        return $this->urlGenerator->generate(
            'basket_shared_pay',
            [
                'number' => $basket->getNumber(),
                'shareToken' => $this->shareToken($basket),
                // The page it opens is written for somebody settling an order that is not theirs: told the customer is the one coming back, it says so in their words instead
                'reminder' => 1,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    // The link that stops the reminders, carried by every one of them: what makes the follow-up of an unpaid order something other than prospection is that the person it goes to can end it in one click
    private function unsubscribeUrl(Basket $basket): string
    {
        return $this->urlGenerator->generate(
            'basket_reminder_unsubscribe',
            [
                'number' => $basket->getNumber(),
                'shareToken' => $this->shareToken($basket),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    // The share token both links are built on, minted on the spot when the order has none: it is only handed out when somebody asks to share their order, and an abandoned basket never did
    private function shareToken(Basket $basket): string
    {
        if (null === $basket->getShareToken()) {
            $basket->setShareToken($this->basketService->generateSecurityToken());
            $this->entityManager->flush();
        }

        return $basket->getShareToken();
    }
}
