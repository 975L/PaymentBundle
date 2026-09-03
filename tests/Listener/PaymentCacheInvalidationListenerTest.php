<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Listener\PaymentCacheInvalidationListener;
use c975L\PaymentBundle\Service\PaymentBlockCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// Which change drops the cached delivery block - UiBundle only ever invalidates the Block that was edited, and knows nothing of the grid that block states its prices from
class PaymentCacheInvalidationListenerTest extends TestCase
{
    private array $invalidated;

    protected function setUp(): void
    {
        $this->invalidated = [];
    }

    public function testAChangeToTheGridDropsTheBlock(): void
    {
        foreach ([new ShippingRate(), new ShippingZone()] as $entity) {
            $this->invalidated = [];
            $this->listen($entity);

            $this->assertSame([[PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING]], $this->invalidated, $entity::class);
        }
    }

    // Every entity of the site travels through these events, a paid basket included, and the block states nothing of them
    public function testAnotherEntityOfTheBundleDropsNothing(): void
    {
        $this->listen(new Payment());

        $this->assertSame([], $this->invalidated);
    }

    public function testTheThresholdAndTheCurrencyDropTheBlock(): void
    {
        foreach (['shop-shipping-free', 'shop-currency'] as $slug) {
            $this->invalidated = [];
            $listener = $this->createListener();
            $manager = $this->createStub(EntityManagerInterface::class);

            $listener->postUpdate(new PostUpdateEventArgs($this->config($slug), $manager));
            $this->assertSame([], $this->invalidated, 'nothing until the flush is over');

            $listener->postFlush(new PostFlushEventArgs($manager));
            $this->assertSame([[PaymentBlockCacheInvalidator::CACHE_TAG_SHIPPING]], $this->invalidated, $slug);
        }
    }

    // The whole settings group is saved at once: the tag goes once, not once per row
    public function testAGroupOfSettingsDropsTheTagOnlyOnce(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        foreach (['shop-shipping-free', 'shop-currency'] as $slug) {
            $listener->postUpdate(new PostUpdateEventArgs($this->config($slug), $manager));
        }

        $listener->postFlush(new PostFlushEventArgs($manager));

        $this->assertCount(1, $this->invalidated);
    }

    public function testASettingTheBlockDoesNotReadDropsNothing(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        $listener->postUpdate(new PostUpdateEventArgs($this->config('site-name'), $manager));
        $listener->postFlush(new PostFlushEventArgs($manager));

        $this->assertSame([], $this->invalidated);
    }

    // A brand new tier on an already-cached block is an INSERT, for which postUpdate never fires
    public function testTheThreeEventsAllInvalidate(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);
        $rate = new ShippingRate();

        $listener->postPersist(new PostPersistEventArgs($rate, $manager));
        $listener->postUpdate(new PostUpdateEventArgs($rate, $manager));
        $listener->preRemove(new PreRemoveEventArgs($rate, $manager));

        $this->assertCount(3, $this->invalidated);
    }

    private function config(string $slug): Config
    {
        $config = new Config();
        $config->setSlug($slug);

        return $config;
    }

    private function listen(object $entity): void
    {
        $this->createListener()->postUpdate(new PostUpdateEventArgs($entity, $this->createStub(EntityManagerInterface::class)));
    }

    private function createListener(): PaymentCacheInvalidationListener
    {
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willReturnCallback(function (array $tags): bool {
            $this->invalidated[] = $tags;

            return true;
        });

        return new PaymentCacheInvalidationListener(new PaymentBlockCacheInvalidator($cache));
    }
}
