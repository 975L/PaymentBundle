<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Service;

use c975L\PaymentBundle\Email\BasketEmailSender;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketReminderService;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reminding a customer who validated an order and never paid it.
 *
 * Two reminders and then silence, which is what the count on the basket is for. The dates are the delicate part:
 * the reminder must leave "modification" exactly as it found it, that date being the one the retention pass reads
 * to know when the visitor last touched their order.
 */
class BasketReminderServiceTest extends TestCase
{
    private bool $sends = true;

    /** @var array<string, mixed> what the last reminder handed its template */
    private array $context = [];

    // A shop being tried out is not selling: its orders are rehearsals, and their addresses are the shopkeeper's own
    public function testNothingGoesOutWhileTheShopIsInTestMode(): void
    {
        $basket = $this->basket();

        $this->assertSame(0, $this->service([$basket], testMode: true)->send());
        $this->assertSame(0, $basket->getRemindersSent());
    }

    // The count is what tells the first reminder from the second, and the second from never again
    public function testAFirstReminderRaisesTheCountToOne(): void
    {
        $basket = $this->basket();

        $this->assertSame(1, $this->service([$basket])->send());
        $this->assertSame(1, $basket->getRemindersSent());
    }

    // The retention pass reads "modification" to know when the visitor last came back: a reminder writing to it would push the purge back every time it fires, and an abandoned order would never go away
    public function testAReminderLeavesTheModificationDateAlone(): void
    {
        $modification = new \DateTime('2026-01-01 10:00:00');
        $basket = $this->basket()->setModification($modification);

        $this->service([$basket])->send();

        $this->assertEquals(new \DateTime('2026-01-01 10:00:00'), $basket->getModification());
    }

    // Counted only once the e-mail is really gone: a shop whose mailer was down for an evening reminds its customers the day after instead of never
    public function testAFailedSendLeavesTheCountWhereItWas(): void
    {
        $this->sends = false;
        $basket = $this->basket();

        $this->assertSame(0, $this->service([$basket])->send());
        $this->assertSame(0, $basket->getRemindersSent());
    }

    // The share token is only handed out when somebody shares their order, so an abandoned basket has none and cannot be linked back to without one
    public function testABasketWithoutAShareTokenIsGivenOne(): void
    {
        $basket = $this->basket();

        $this->service([$basket])->send();

        $this->assertNotNull($basket->getShareToken());
    }

    /**
     * The way out carried by every reminder.
     *
     * What makes the follow-up of an unpaid order something other than prospection is that the person it goes
     * to can end it in one click, so a reminder leaving without that link is one that should not have left.
     */
    public function testEveryReminderCarriesTheLinkThatStopsThem(): void
    {
        $basket = $this->basket();

        $this->service([$basket])->send();

        $this->assertStringContainsString('basket_reminder_unsubscribe', $this->context['unsubscribe_url']);
        $this->assertStringContainsString($basket->getShareToken(), $this->context['unsubscribe_url']);
        // The same token opens both, minted once for the two of them rather than twice over
        $this->assertStringContainsString($basket->getShareToken(), $this->context['pay_url']);
    }

    private function basket(): Basket
    {
        return new Basket()
            ->setStatus('validated')
            ->setEmail('someone@example.org')
            ->setModification(new \DateTime('-2 days'))
        ;
    }

    /**
     * @param Basket[] $due the baskets owed their first reminder
     */
    private function service(array $due, bool $testMode = false): BasketReminderService
    {
        $repository = $this->createStub(BasketRepository::class);
        // Only the first round has anything to hand back: the second asks for the baskets that already had one
        $repository->method('findToRemind')->willReturnCallback(
            fn (int $days, int $alreadySent): array => 0 === $alreadySent ? $due : []
        );

        $emailSender = $this->createStub(BasketEmailSender::class);
        $emailSender->method('send')->willReturnCallback(function (Basket $basket, string $subjectKey, string $template, array $context = [], ?string $to = null): bool {
            $this->context = $context;

            return $this->sends;
        });

        $basketService = $this->createStub(BasketServiceInterface::class);
        $basketService->method('generateSecurityToken')->willReturn('0123456789abcdef');

        $testModeStub = $this->createStub(PaymentTestModeInterface::class);
        $testModeStub->method('isEnabled')->willReturn($testMode);

        // Echoes back the route and the parameters it was handed, the real generator's own output being Symfony's business rather than this bundle's
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            fn (string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string => 'https://example.org/' . $name . '?' . http_build_query($parameters)
        );

        return new BasketReminderService(
            $repository,
            $emailSender,
            $basketService,
            $this->createStub(EntityManagerInterface::class),
            $testModeStub,
            $urlGenerator,
            $this->createStub(LoggerInterface::class),
        );
    }
}
