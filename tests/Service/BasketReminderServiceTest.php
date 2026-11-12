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
use c975L\PaymentBundle\Enum\BasketStatus;
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

    // The number of the basket whose e-mail throws, null for none
    private ?string $throwFor = null;

    /** @var array<string, mixed> what the last reminder handed its template */
    private array $context = [];

    /** @var array<string, string> */
    private array $headers = [];

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

    // The mail client's own unsubscribe button points at the same way out as the link in the body, one-click (RFC 8058)
    public function testAReminderCarriesTheOneClickUnsubscribeHeaders(): void
    {
        $this->service([$this->basket()])->send();

        $this->assertSame('<' . $this->context['unsubscribe_url'] . '>', $this->headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $this->headers['List-Unsubscribe-Post']);
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

    // One basket throwing is logged and the next one still reminded, each count written as soon as its e-mail is gone
    public function testABasketThrowingDoesNotStopTheOthers(): void
    {
        $failing = $this->basket()->setNumber('item-1');
        $next = $this->basket()->setNumber('item-2');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        // The token minted for each, then one count written for the one that went out
        $entityManager->expects($this->exactly(3))->method('flush');
        $entityManager->method('isOpen')->willReturn(true);

        $this->throwFor = 'item-1';
        $this->assertSame(1, $this->service([$failing, $next], entityManager: $entityManager)->send());
        $this->assertSame(0, $failing->getRemindersSent());
        $this->assertSame(1, $next->getRemindersSent());
    }

    // A flush failing closes the entity manager: the pass stops there rather than e-mail reminders it can no longer count
    public function testAClosedEntityManagerStopsThePass(): void
    {
        $failing = $this->basket()->setNumber('item-1');
        $next = $this->basket()->setNumber('item-2');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        $this->throwFor = 'item-1';
        $this->assertSame(0, $this->service([$failing, $next], entityManager: $entityManager)->send());
        $this->assertSame(0, $next->getRemindersSent());
    }

    private function basket(): Basket
    {
        return new Basket()
            ->setStatus(BasketStatus::Validated)
            ->setEmail('someone@example.org')
            ->setModification(new \DateTime('-2 days'))
        ;
    }

    /**
     * @param Basket[] $due the baskets owed their first reminder
     */
    private function service(array $due, bool $testMode = false, ?EntityManagerInterface $entityManager = null): BasketReminderService
    {
        $repository = $this->createStub(BasketRepository::class);
        // Only the first round has anything to hand back: the second asks for the baskets that already had one
        $repository->method('findToRemind')->willReturnCallback(
            fn (int $days, int $alreadySent): array => 0 === $alreadySent ? $due : []
        );

        $emailSender = $this->createStub(BasketEmailSender::class);
        $emailSender->method('send')->willReturnCallback(function (Basket $basket, string $subjectKey, string $template, array $context = [], ?string $to = null, array $headers = []): bool {
            if (null !== $this->throwFor && $this->throwFor === $basket->getNumber()) {
                throw new \RuntimeException('mailer down');
            }

            $this->context = $context;
            $this->headers = $headers;

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
            $entityManager ?? $this->openEntityManager(),
            $testModeStub,
            $urlGenerator,
            $this->createStub(LoggerInterface::class),
        );
    }

    // A stub answers false to isOpen() unless told otherwise, which would end every pass after its first basket
    private function openEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);

        return $entityManager;
    }
}
