<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Integration;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\InAppProductProviderInterface;
use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Contract\InAppTransaction;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Entity\InvoiceSequence;
use c975L\PaymentBundle\Enum\BasketStatus;
use c975L\PaymentBundle\Enum\PaymentEvent;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\InAppProductProviderRegistry;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Repository\DiscountRepository;
use c975L\PaymentBundle\Repository\GiftCardRepository;
use c975L\PaymentBundle\Repository\InvoiceSequenceRepository;
use c975L\PaymentBundle\Repository\StorePurchaseRepository;
use c975L\PaymentBundle\Service\BasketCodeService;
use c975L\PaymentBundle\Service\BasketRefundService;
use c975L\PaymentBundle\Service\BasketRetentionService;
use c975L\PaymentBundle\Service\BasketService;
use c975L\PaymentBundle\Service\GiftCardService;
use c975L\PaymentBundle\Service\InAppPurchaseService;
use c975L\PaymentBundle\Service\InvoiceService;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use c975L\PaymentBundle\Service\ShippingRateResolverInterface;
use c975L\PaymentBundle\Service\VatCalculator;
use c975L\PaymentBundle\Tests\Integration\Fixture\DatabaseTestUser;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The whole journey of a code and of a charge, on the real entities, the real repositories and a real database.
 *
 * The other tests read the SQL as strings or play it with fakes: these execute it. Each "request" starts from an emptied entity manager, as a new request does in production.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MoneyOnADatabaseTest extends TestCase
{
    private const string CARD = 'AAAABBBBCCCCDDDD';

    private EntityManager $entityManager;

    private ManagerRegistry $registry;

    private BasketRepository $basketRepository;

    private BasketCodeService $codeService;

    private BasketRefundService $refundService;

    private Session $session;

    private \Closure $onPaid;

    private bool $checkoutFails = false;

    /** @var list<string> */
    private array $expired = [];

    /** @var list<string> */
    private array $critical = [];

    private int $checkouts = 0;

    private ?PaymentNotification $returnNotification = null;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2) . '/src/Entity', __DIR__ . '/Fixture'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $config->enableNativeLazyObjects(true);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, DatabaseTestUser::class, []);
        $resolver->addResolveTargetEntity('c975L\\ConfigBundle\\Contract\\UserInterface', DatabaseTestUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(fn (): EntityManager => $this->entityManager);
        $this->registry = $registry;

        // The repositories the way the container builds them, each taking the registry
        $config->setRepositoryFactory(new class ($registry) implements RepositoryFactory {
            /** @var array<string, ObjectRepository<object>> */
            private array $repositories = [];

            public function __construct(private readonly ManagerRegistry $registry)
            {
            }

            public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
            {
                $class = $entityManager->getClassMetadata($entityName)->customRepositoryClassName ?? EntityRepository::class;
                $this->repositories[$entityName] ??= EntityRepository::class === $class ? new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName)) : new $class($this->registry);

                return $this->repositories[$entityName];
            }
        });

        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
        new SchemaTool($this->entityManager)->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->basketRepository = new BasketRepository($registry);
        $this->onPaid = static function (): void {
        };

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $this->codeService = new BasketCodeService(new DiscountRepository($registry), new GiftCardRepository($registry), $translator, $this->createStub(PaymentTestModeInterface::class), $this->basketRepository);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : '');

        $invoiceService = new InvoiceService(new InvoiceSequenceRepository($registry), $this->entityManager, $configService, $this->createStub(PdfGeneratorInterface::class), new VatCalculator($this->createStub(BasketItemProviderRegistry::class)));

        $this->refundService = new BasketRefundService($this->basketRepository, $this->codeService, new GiftCardService(new GiftCardRepository($registry), $this->entityManager, $this->createStub(PaymentTestModeInterface::class), $configService), $invoiceService, $this->entityManager, $this->logger(), new BasketItemProviderRegistry([]));
    }

    // Every statement of the lot, on the schema the entities really generate: a column named wrong fails here and nowhere else
    public function testTheSchemaHoldsTheTwoNewColumns(): void
    {
        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_basket WHERE discount_claimed = 1'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_store_purchase WHERE delivered IS NOT NULL'));
    }

    public function testAMarkerIsTakenAndGivenBackOnce(): void
    {
        $basket = $this->storedBasket(BasketStatus::Validated);

        $this->assertTrue($this->basketRepository->markCodeClaimed($basket));
        $this->assertFalse($this->basketRepository->markCodeClaimed($basket));
        $this->assertTrue($this->basketRepository->markCodeReleased($basket));
        $this->assertFalse($this->basketRepository->markCodeReleased($basket));
    }

    public function testTheCardStatementsRefuseWhatTheCardDoesNotHold(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 1500);

        $this->assertFalse($this->codeService->claim($basket));
        $this->assertSame(1000, $this->balance());
        $this->assertFalse($this->marker($basket));

        $basket->setDiscountAmount(600);
        $this->assertTrue($this->codeService->claim($basket));
        $this->assertSame(400, $this->balance());
        $this->assertTrue($this->marker($basket));
    }

    public function testAClaimReleasedTwiceCreditsTheCardOnce(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $this->codeService->claim($basket);

        $this->codeService->release($basket);
        $this->codeService->release($basket);

        $this->assertSame(1000, $this->balance());
    }

    public function testADiscountQuotaIsTakenAndGivenBack(): void
    {
        $discount = new Discount()->setCode('NOEL')->setKind(Discount::KIND_AMOUNT)->setValue(500)->setMaxUses(1)->setActive(true);
        $this->entityManager->persist($discount);
        $this->entityManager->flush();

        $first = $this->storedBasket(BasketStatus::Validated, code: 'NOEL', kind: Basket::CODE_KIND_DISCOUNT, amount: 500);
        $second = $this->storedBasket(BasketStatus::Validated, code: 'NOEL', kind: Basket::CODE_KIND_DISCOUNT, amount: 500);

        $this->assertTrue($this->codeService->claim($first));
        $this->assertFalse($this->codeService->claim($second));

        $this->codeService->release($first);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT used_count FROM payment_discount'));
        $this->assertTrue($this->codeService->claim($second));
    }

    // Add, code applied, validate, pay, webhook, return: the customer's own path, the card debited once and the order delivered once
    public function testTheNormalJourneyWebhookFirst(): void
    {
        $this->storedCard(1000);
        $delivered = 0;
        $this->onPaid = static function () use (&$delivered): void {
            ++$delivered;
        };

        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $service = $this->service($basket);
        $service->validate(new Request());
        $this->assertSame(0, $this->balance());

        $service = $this->newRequest($basket);
        $service->applyNotification($this->paid($basket));
        $service = $this->newRequest($basket);
        $reloaded = $this->reload($basket);
        $service->confirmReturn($reloaded, new Request());
        $service->applyNotification($this->paid($basket));

        $reloaded = $this->reload($basket);
        $this->assertSame(BasketStatus::Paid, $reloaded->getStatus());
        $this->assertSame(1, $delivered);
        $this->assertSame(0, $this->balance());
        $this->assertNotNull($reloaded->getInvoiceNumber());
        $this->assertSame(1, (int) $this->entityManager->getRepository(InvoiceSequence::class)->findAll()[0]->getLastNumber());
    }

    public function testTheNormalJourneyReturnFirst(): void
    {
        $this->storedCard(1000);
        $delivered = 0;
        $this->onPaid = static function () use (&$delivered): void {
            ++$delivered;
        };

        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());

        $service = $this->newRequest($basket);
        $this->returnNotification = $this->paid($basket);
        $service->confirmReturn($this->reload($basket), new Request());
        $this->assertSame(BasketStatus::Paid, $this->reload($basket)->getStatus());
        $this->assertNull($this->session->get('basket'));

        $this->newRequest($basket)->applyNotification($this->paid($basket));
        $this->assertSame(1, $delivered);
        $this->assertSame(0, $this->balance());
    }

    // A code covering everything takes the free path: no gateway, no payment, the code spent once all the same
    public function testABasketFullyCoveredByItsCode(): void
    {
        $this->storedCard(5000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 3000);

        $this->service($basket)->validate(new Request());
        $this->assertSame(2000, $this->balance());
        $this->assertSame(0, $this->checkouts);

        $this->newRequest($basket)->confirmReturn($this->reload($basket), new Request());

        $this->assertSame(BasketStatus::Paid, $this->reload($basket)->getStatus());
        $this->assertSame(2000, $this->balance());
    }

    // Back from the gateway and validating again: the old checkout called off, the code let go and taken again, the card debited once
    public function testGoingBackAndValidatingAgainCallsTheOldCheckoutOff(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $firstNumber = $this->reload($basket)->getNumber();

        $this->newRequest($basket)->validate(new Request());

        $reloaded = $this->reload($basket);
        $this->assertSame(['cs_1'], $this->expired);
        $this->assertSame(2, $this->checkouts);
        $this->assertSame(0, $this->balance());
        $this->assertTrue($this->marker($basket));
        $this->assertSame('cs_2', $reloaded->getPayment()?->getGatewayReference());
        $this->assertNotSame($firstNumber, $reloaded->getNumber());
    }

    public function testACheckoutThatCannotOpenGivesTheCodeBack(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->checkoutFails = true;

        try {
            $this->service($basket)->validate(new Request());
            $this->fail('The provider refused the checkout');
        } catch (\RuntimeException) {
            $this->assertSame(1000, $this->balance());
            $this->assertFalse($this->marker($basket));
        }
    }

    // A provider throwing: everything written in the delivery is rolled back, the order goes back for a retry, the retry delivers it whole
    public function testAFailedDeliveryRollsBackAndARetryDeliversOnce(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());

        $connection = $this->entityManager->getConnection();
        $this->onPaid = static function () use ($connection): void {
            $connection->executeStatement('UPDATE payment_gift_card SET initial_amount = initial_amount + 1');

            throw new \RuntimeException('provider down');
        };

        try {
            $this->newRequest($basket)->applyNotification($this->paid($basket));
            $this->fail('The webhook has to answer 500');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider down', $exception->getMessage());
        }

        $held = $this->reload($basket);
        $this->assertSame(BasketStatus::Validated, $held->getStatus());
        $this->assertTrue($held->isChargedUndelivered());
        $this->assertSame(5000, (int) $connection->fetchOne('SELECT initial_amount FROM payment_gift_card'));
        $this->assertSame(0, $this->balance());
        $this->assertNull($held->getInvoiceNumber());
        $this->assertSame(1, $this->basketRepository->countChargedUndeliveredSince(new \DateTime('-1 day')));

        $this->onPaid = static function (): void {
        };
        $this->newRequest($basket)->applyNotification($this->paid($basket));

        $delivered = $this->reload($basket);
        $this->assertSame(BasketStatus::Paid, $delivered->getStatus());
        $this->assertSame(0, $this->balance());
        $this->assertNotNull($delivered->getInvoiceNumber());
    }

    // A flush failing closes the entity manager: the order still goes back to "validated" on the connection, and what the caller sees is the cause
    public function testAFlushClosingTheManagerStillMovesTheOrderBack(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());

        $entityManager = $this->entityManager;
        $this->onPaid = static function () use ($entityManager): void {
            $entityManager->persist(new GiftCard()->setCode(self::CARD)->setInitialAmount(1)->setBalance(1)->setCurrency('EUR')->setShareToken('dup'));
        };

        try {
            $this->newRequest($basket)->applyNotification($this->paid($basket));
            $this->fail('The duplicate has to fail the flush');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->assertFalse($this->entityManager->isOpen());
        }

        $this->assertSame('validated', $this->entityManager->getConnection()->fetchOne('SELECT status FROM payment_basket'));
        $this->assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    // What a closed manager leaves is still given back, and the cause is what the caller is told rather than "the manager is closed"
    public function testACheckoutThatCannotFlushGivesTheCodeBackAndNamesTheCause(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $this->codeService->claim($basket);
        $this->entityManager->close();

        try {
            $this->codeService->release($basket);
        } catch (EntityManagerClosed) {
            $this->fail('A closed manager must not hide the cause');
        }

        $this->assertSame(1000, $this->balance());
    }

    // What a provider had persisted and not flushed when it threw is not written by a later flush of the same request
    public function testAFailedDeliveryLeavesNothingToALaterFlush(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());

        $entityManager = $this->entityManager;
        $this->onPaid = static function () use ($entityManager): void {
            $entityManager->persist(new GiftCard()->setCode('ISSUEDBYDELIVERY')->setInitialAmount(1)->setBalance(1)->setCurrency('EUR')->setShareToken('issued'));

            throw new \RuntimeException('next provider down');
        };

        $this->returnNotification = $this->paid($basket);
        $service = $this->newRequest($basket);
        $service->confirmReturn($this->reload($basket), new Request());
        $this->entityManager->flush();

        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_gift_card'));
    }

    // The nightly pass let go of the card while the payment's request already held the order, marker included: the payment has to debit
    public function testASweepBetweenReadingTheOrderAndDeliveringItStillDebits(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $service = $this->newRequest($basket);
        $held = $this->reload($basket);
        $held->getPayment()?->setFinished(true);
        $this->entityManager->flush();
        $this->assertTrue($held->isDiscountClaimed());

        // The other process: its statements, on the same database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE payment_basket SET discount_claimed = 0');
        $connection->executeStatement('UPDATE payment_gift_card SET balance = balance + 1000');
        $this->assertSame(1000, $this->balance());

        $service->paid($held);

        $this->assertSame(BasketStatus::Paid, $held->getStatus());
        $this->assertSame(0, $this->balance());
        $this->assertTrue($this->marker($basket));
    }

    // The other order of that race: the pass read the order as given up on, and the payment delivered it before the pass got to it
    public function testASweepReachingAnOrderJustPaidGivesNothingBack(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $swept = $this->reload($basket);

        $this->entityManager->getConnection()->executeStatement("UPDATE payment_basket SET status = 'paid'");

        $this->codeService->release($swept);

        $this->assertSame(0, $this->balance());
        $this->assertTrue($this->marker($basket));
    }

    public function testThePassLetsGoOfACheckoutGivenUpOnAndNothingElse(): void
    {
        $this->storedCard(9000);
        $givenUp = $this->storedBasket(BasketStatus::Validated, amount: 1000);
        $this->attachPayment($givenUp, finished: false);
        $noPayment = $this->storedBasket(BasketStatus::Validated, amount: 2000);
        $charged = $this->storedBasket(BasketStatus::Validated, amount: 3000);
        $this->attachPayment($charged, finished: true);
        $shared = $this->storedBasket(BasketStatus::Validated, amount: 3000);
        $shared->setShareToken('sharedtoken');
        $this->entityManager->flush();
        array_map($this->codeService->claim(...), [$givenUp, $noPayment, $charged, $shared]);
        $this->assertSame(0, $this->balance());

        $this->entityManager->getConnection()->executeStatement('UPDATE payment_basket SET modification = :old', ['old' => new \DateTime('-25 hours')->format('Y-m-d H:i:s')]);
        $this->entityManager->clear();

        $retention = new BasketRetentionService($this->basketRepository, $this->entityManager, $this->logger(), $this->codeService, $this->shareValidity(7));
        $this->assertSame(2, $retention->releaseStaleClaims());
        $this->assertSame(0, $retention->releaseStaleClaims());

        $this->assertSame(3000, $this->balance());
        $this->assertFalse($this->marker($givenUp));
        $this->assertFalse($this->marker($noPayment));
        $this->assertTrue($this->marker($charged));
        $this->assertTrue($this->marker($shared));
    }

    // A shared order holds its code for as long as the checkout said its link lasts, and not for the thirty days of the purge: its order stays, and following the link later claims it afresh
    public function testASharedLinkRunOutGivesItsCodeBackAndNotBefore(): void
    {
        $this->storedCard(9000);
        $running = $this->storedBasket(BasketStatus::Validated, amount: 1000);
        $running->setShareToken('runningtoken');
        $runOut = $this->storedBasket(BasketStatus::Validated, amount: 2000);
        $runOut->setShareToken('runouttoken');
        $paid = $this->storedBasket(BasketStatus::Validated, amount: 3000);
        $paid->setShareToken('paidtoken');
        $this->attachPayment($paid, finished: true);
        $this->entityManager->flush();
        array_map($this->codeService->claim(...), [$running, $runOut, $paid]);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE payment_basket SET modification = :old', ['old' => new \DateTime('-6 days')->format('Y-m-d H:i:s')]);
        $connection->executeStatement('UPDATE payment_basket SET modification = :old WHERE id IN (:one, :two)', ['old' => new \DateTime('-8 days')->format('Y-m-d H:i:s'), 'one' => $runOut->getId(), 'two' => $paid->getId()]);
        $this->entityManager->clear();

        $retention = new BasketRetentionService($this->basketRepository, $this->entityManager, $this->logger(), $this->codeService, $this->shareValidity(7));

        $this->assertSame(1, $retention->releaseStaleClaims());
        $this->assertFalse($this->marker($runOut));
        $this->assertTrue($this->marker($running));
        $this->assertTrue($this->marker($paid));
        $this->assertSame(BasketStatus::Validated, $this->basketRepository->find($runOut->getId())?->getStatus());
    }

    private function shareValidity(int $days): ConfigServiceInterface
    {
        return $this->createConfiguredStub(ConfigServiceInterface::class, ['hasParameter' => true, 'get' => (string) $days]);
    }

    public function testAbandonedAndRemindedOrdersLeaveTheChargedOnesAlone(): void
    {
        $unpaid = $this->storedBasket(BasketStatus::Validated);
        $charged = $this->storedBasket(BasketStatus::Validated);
        $this->attachPayment($charged, finished: true);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE payment_basket SET modification = :old', ['old' => new \DateTime('-40 days')->format('Y-m-d H:i:s')]);
        $this->entityManager->clear();

        $this->assertSame([$unpaid->getId()], array_map(static fn (Basket $basket): ?int => $basket->getId(), $this->basketRepository->findAbandoned(30)));
        $this->assertSame([$unpaid->getId()], array_map(static fn (Basket $basket): ?int => $basket->getId(), $this->basketRepository->findToRemind(1, 0)));
    }

    public function testARefundOfAnOrderChargedAndHeldCreditsOnlyWhatItTook(): void
    {
        $this->storedCard(1000);

        // Holds its claim
        $claimed = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $this->attachPayment($claimed, finished: true, transaction: 'pi_claimed');
        $this->codeService->claim($claimed);
        $this->assertSame(400, $this->balance());

        // The code refused it at delivery: nothing was taken
        $unclaimed = $this->storedBasket(BasketStatus::Validated, amount: 300);
        $this->attachPayment($unclaimed, finished: true, transaction: 'pi_unclaimed');

        $this->refundService->apply(new PaymentNotification(null, 'stripe', 'pi_unclaimed', null, 2700, PaymentEvent::Refunded));
        $this->assertSame(400, $this->balance());
        $this->assertSame('refunded', $this->storedStatus($unclaimed));

        $this->refundService->apply(new PaymentNotification(null, 'stripe', 'pi_claimed', null, 2400, PaymentEvent::Refunded));
        $this->assertSame(1000, $this->balance());
        $this->assertSame('refunded', $this->storedStatus($claimed));

        // The replay of a refund credits nothing more
        $this->refundService->apply(new PaymentNotification(null, 'stripe', 'pi_claimed', null, 2400, PaymentEvent::Refunded));
        $this->assertSame(1000, $this->balance());
    }

    public function testARefundOfASettledOrderCreditsTheCard(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $this->newRequest($basket)->applyNotification($this->paid($basket));
        $this->assertSame(0, $this->balance());

        $this->refundService->apply(new PaymentNotification(null, 'stripe', 'pi_' . $basket->getId(), null, 2000, PaymentEvent::Refunded));

        $this->assertSame(1000, $this->balance());
        $this->assertSame('refunded', $this->storedStatus($basket));
    }

    // A second charge on an order already recorded is the shop's to hand back: said, and the first charge stays the one a refund finds
    public function testASecondChargeDoesNotOverwriteTheFirst(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $this->newRequest($basket)->applyNotification($this->paid($basket));

        $this->newRequest($basket)->applyNotification(new PaymentNotification((string) $basket->getId(), 'stripe', 'pi_other', 'card', 2000));

        $this->assertSame('pi_' . $basket->getId(), $this->reload($basket)->getPayment()?->getTransactionId());
        $this->assertNotSame([], array_filter($this->critical, static fn (string $line): bool => str_contains($line, 'charged again')));
    }

    // Paid on the old checkout while the customer had reopened the basket: no order to deliver, and the shop has to hear of it
    public function testAChargeOnAReopenedBasketIsSaidAndWritesNothing(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::New, amount: 1000);
        $this->service($basket)->validate(new Request());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE payment_basket SET status = 'new'");

        $this->newRequest($basket)->applyNotification($this->paid($basket));

        $this->assertFalse($this->reload($basket)->getPayment()?->isFinished());
        $this->assertNotSame([], array_filter($this->critical, static fn (string $line): bool => str_contains($line, 'reopened')));
    }

    // The reminder's way back, past the nightly pass: a code spent elsewhere meanwhile refuses the checkout rather than charging for what cannot be delivered
    public function testASharedPaymentWhoseCodeWentElsewhereOpensNoCheckout(): void
    {
        $this->storedCard(300);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $basket->setNumber('202611-AB-12345')->setSecurityToken('a')->setShareToken('b');
        $this->entityManager->flush();

        $this->expectException(PaymentUnavailableException::class);

        try {
            $this->service($basket)->payShared($basket);
        } finally {
            $this->assertSame(0, $this->checkouts);
        }
    }

    public function testASharedPaymentTakesTheCodeBackBeforeOpeningItsCheckout(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $basket->setNumber('202611-AB-12345')->setSecurityToken('a')->setShareToken('b');
        $this->entityManager->flush();

        $this->service($basket)->payShared($basket);

        $this->assertSame(400, $this->balance());
        $this->assertSame(1, $this->checkouts);
    }

    // The usual shared order, holding its claim since validate(): the payer's checkout debits nothing more
    public function testASharedPaymentHoldingItsClaimDebitsOnce(): void
    {
        $this->storedCard(1000);
        $basket = $this->storedBasket(BasketStatus::Validated, amount: 600);
        $basket->setNumber('202611-AB-12345')->setSecurityToken('a')->setShareToken('b');
        $this->entityManager->flush();
        $this->codeService->claim($basket);

        $this->service($basket)->payShared($basket);
        $this->service($basket)->payShared($basket);

        $this->assertSame(400, $this->balance());
        $this->assertSame(['cs_1'], $this->expired);
    }

    public function testARefusedValidateLeavesTheCardWhole(): void
    {
        $this->storedCard(100);
        $basket = $this->storedBasket(BasketStatus::New, amount: 600);

        $this->expectException(BasketNotOrderableException::class);

        try {
            $this->service($basket)->validate(new Request());
        } finally {
            $this->assertSame(100, $this->balance());
            $this->assertFalse($this->marker($basket));
        }
    }

    // Bought, delivered once whatever the replays, retried when the delivery failed, delivered again only after a refund reversed
    public function testAStoreTransactionIsDeliveredOnce(): void
    {
        $deliveries = 0;
        $fails = true;
        $provider = $this->createStub(InAppProductProviderInterface::class);
        $provider->method('deliver')->willReturnCallback(static function () use (&$deliveries, &$fails): void {
            if ($fails) {
                throw new \RuntimeException('down');
            }
            ++$deliveries;
        });

        $service = $this->storeService($provider, 'production');

        try {
            $service->verify('apple', 'holder', ['holder' => 'holder']);
            $this->fail('The delivery failed');
        } catch (\RuntimeException) {
            $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT delivered FROM payment_store_purchase'));
        }

        $fails = false;
        $this->assertTrue($service->verify('apple', 'holder', ['holder' => 'holder']));
        $this->assertTrue($service->verify('apple', 'holder', ['holder' => 'holder']));
        $this->assertSame(1, $deliveries);
        $this->assertNotNull($this->entityManager->getConnection()->fetchOne('SELECT delivered FROM payment_store_purchase'));
    }

    public function testASandboxPurchaseGoesOnlyToAHolderTheShopNamed(): void
    {
        $provider = $this->createStub(InAppProductProviderInterface::class);
        $service = $this->storeService($provider, 'sandbox');

        $this->assertFalse($service->verify('apple', 'stranger', ['holder' => 'stranger']));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_store_purchase'));
        $this->assertTrue($service->verify('apple', 'reviewer', ['holder' => 'reviewer']));
        $this->assertSame('sandbox', $this->entityManager->getConnection()->fetchOne('SELECT environment FROM payment_store_purchase'));
    }

    // A line stored above the cap, by an order of before it: the "-" still walks it down
    public function testALineAboveTheCapCanStillBeWalkedDown(): void
    {
        $basket = $this->storedBasket(BasketStatus::New);
        $items = $basket->getItems();
        $items['product'][1]['quantity'] = BasketService::MAX_LINE_QUANTITY + 5;
        $basket->setItems($items);
        $this->entityManager->flush();

        $service = $this->service($basket, findItem: new class {
            public function getId(): int
            {
                return 1;
            }
        });
        $service->addItem(new Request(content: (string) json_encode(['id' => 1, 'quantity' => -1, 'type' => 'product'])));

        $this->assertSame(BasketService::MAX_LINE_QUANTITY + 4, $this->reload($basket)->getItems()['product'][1]['quantity']);
    }

    private function storeService(InAppProductProviderInterface $provider, string $environment): InAppPurchaseService
    {
        $store = $this->createStub(InAppStoreInterface::class);
        $store->method('getSlug')->willReturn('apple');
        $store->method('isConfigured')->willReturn(true);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('reviewer, tester');

        $providers = $this->createStub(InAppProductProviderRegistry::class);
        $providers->method('forProduct')->willReturn($provider);

        $service = null;
        $store->method('verifyPurchase')->willReturnCallback(static function (array $proof) use (&$service, $environment): InAppTransaction {
            return new InAppTransaction('apple', 'product', 'transaction', $service->accountToken($proof['holder']), $environment);
        });

        $service = new InAppPurchaseService(new InAppStoreRegistry([$store]), $providers, new StorePurchaseRepository($this->registry), $this->entityManager, $configService, 'secret');

        return $service;
    }

    private function storedCard(int $balance): GiftCard
    {
        $card = new GiftCard()->setCode(self::CARD)->setInitialAmount(5000)->setBalance($balance)->setCurrency('EUR')->setActive(true)->setShareToken('share' . $balance);
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function storedBasket(BasketStatus $status, string $code = self::CARD, string $kind = Basket::CODE_KIND_GIFT_CARD, int $amount = 0): Basket
    {
        $basket = new Basket();
        $basket->setStatus($status);
        $basket->setTotal(3000);
        $basket->setShipping(0);
        $basket->setQuantity(1);
        $basket->setCurrency('EUR');
        $basket->setEmail('marie@example.org');
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setContentFlags(Basket::CONTENT_FLAG_DIGITAL);
        $basket->setRecoveryToken(bin2hex(random_bytes(8)));
        $basket->setItems(['product' => [1 => [
            'quantity' => 1,
            'total' => 3000,
            'parent' => ['title' => 'Un livre'],
            'item' => ['title' => 'Broché', 'price' => 3000],
        ]]]);

        if ($amount > 0) {
            $basket->setDiscountCode($code)->setDiscountKind($kind)->setDiscountAmount($amount);
        }

        $this->entityManager->persist($basket);
        $this->entityManager->flush();

        return $basket;
    }

    private function attachPayment(Basket $basket, bool $finished, ?string $transaction = null): void
    {
        $payment = new \c975L\PaymentBundle\Entity\Payment();
        $payment->setCreation(new \DateTime())->setModification(new \DateTime())->setGateway('stripe')->setAmount($basket->getPayable())->setCurrency('EUR')->setFinished($finished)->setTransactionId($transaction);
        $payment->setBasket($basket);
        $basket->setPayment($payment);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
    }

    private function paid(Basket $basket): PaymentNotification
    {
        return new PaymentNotification((string) $basket->getId(), 'stripe', 'pi_' . $basket->getId(), 'card', 3000 - $basket->getDiscountAmount());
    }

    private function reload(Basket $basket): Basket
    {
        $this->entityManager->clear();

        return $this->basketRepository->find($basket->getId());
    }

    private function newRequest(Basket $basket): BasketService
    {
        $this->entityManager->clear();

        return $this->service($basket);
    }

    private function balance(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT balance FROM payment_gift_card');
    }

    private function marker(Basket $basket): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->fetchOne('SELECT discount_claimed FROM payment_basket WHERE id = :id', ['id' => $basket->getId()]);
    }

    private function storedStatus(Basket $basket): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM payment_basket WHERE id = :id', ['id' => $basket->getId()]);
    }

    private function logger(): AbstractLogger
    {
        return new class ($this->critical) extends AbstractLogger {
            /** @param list<string> $critical */
            public function __construct(private array &$critical)
            {
            }

            public function log($level, \Stringable | string $message, array $context = []): void
            {
                if ('critical' === $level) {
                    $this->critical[] = (string) $message;
                }
            }
        };
    }

    // The session naming that basket, and the gateway answering as Stripe does
    private function service(Basket $basket, ?object $findItem = null): BasketService
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('basket', $basket->getId());
        $request = new Request();
        $request->setSession($this->session);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => 'shop-currency' === $slug ? 'EUR' : 0);

        $provider = $this->createStub(BasketItemProviderInterface::class);
        $provider->method('getContentFlags')->willReturn(Basket::CONTENT_FLAG_SERVICE);
        $provider->method('onBasketValidated')->willReturn([]);
        $provider->method('onBasketPaid')->willReturnCallback(fn () => ($this->onPaid)());
        $provider->method('findItem')->willReturn($findItem);
        $provider->method('toBasketData')->willReturnCallback(static fn (object $item, int $quantity): array => ['quantity' => $quantity, 'total' => 3000 * $quantity, 'parent' => ['title' => 'Un livre'], 'item' => ['title' => 'Broché', 'price' => 3000]]);

        $itemProviderRegistry = $this->createStub(BasketItemProviderRegistry::class);
        $itemProviderRegistry->method('get')->willReturn($provider);
        $itemProviderRegistry->method('has')->willReturn(true);

        $gateway = $this->createStubForIntersectionOfInterfaces([PaymentGatewayInterface::class, ExpirableGatewayInterface::class, ReturnAwareGatewayInterface::class]);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('createCheckout')->willReturnCallback(function (): CheckoutSession {
            if ($this->checkoutFails) {
                throw new \RuntimeException('Stripe is down');
            }

            ++$this->checkouts;

            return new CheckoutSession('https://checkout.stripe.com/c/pay/cs_' . $this->checkouts, 'cs_' . $this->checkouts);
        });
        $gateway->method('expireCheckout')->willReturnCallback(function (string $reference): void {
            $this->expired[] = $reference;
        });
        $gateway->method('readReturn')->willReturnCallback(fn (): ?PaymentNotification => $this->returnNotification);

        $gatewayRegistry = $this->createStub(PaymentGatewayRegistry::class);
        $gatewayRegistry->method('getOffered')->willReturn(['stripe' => $gateway]);
        $gatewayRegistry->method('getActive')->willReturn($gateway);
        $gatewayRegistry->method('has')->willReturn(true);
        $gatewayRegistry->method('get')->willReturn($gateway);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://site.example/url');

        return new BasketService(
            $this->basketRepository,
            $configService,
            $this->entityManager,
            new RequestStack([$request]),
            $this->createStub(PaymentFormFactoryInterface::class),
            $translator,
            $messageBus,
            $urlGenerator,
            $this->logger(),
            $this->createStub(TokenStorageInterface::class),
            $itemProviderRegistry,
            $gatewayRegistry,
            $this->createStub(PaymentTestModeInterface::class),
            $this->codeService,
            new VatCalculator($itemProviderRegistry),
            new InvoiceService(new InvoiceSequenceRepository($this->registry), $this->entityManager, $configService, $this->createStub(PdfGeneratorInterface::class), new VatCalculator($itemProviderRegistry)),
            $this->createStub(ShippingRateResolverInterface::class),
            $this->refundService,
        );
    }
}
