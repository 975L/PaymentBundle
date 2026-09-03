<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Service\PaymentBlockCacheInvalidator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

// Drops the cached delivery block whenever what it states changes - a tier added to the grid, a zone's prices edited, the free-delivery threshold moved or the currency changed
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class PaymentCacheInvalidationListener
{
    // The two settings the block reads beside the grid: the threshold it announces free delivery from, and the currency its amounts are printed in
    private const array CONFIG_SLUGS = [
        'shop-shipping-free',
        'shop-currency',
    ];

    // The back-office saves a whole settings group at once, so the rows only raise this flag: dropping the tag on each of them would do it once per row, inside the transaction
    private bool $stale = false;

    public function __construct(private readonly PaymentBlockCacheInvalidator $invalidator)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->stale) {
            return;
        }

        $this->stale = false;
        $this->invalidator->invalidateShipping();
    }

    private function invalidate(object $entity): void
    {
        match (true) {
            $entity instanceof ShippingRate,
            $entity instanceof ShippingZone => $this->invalidator->invalidateShipping(),
            $entity instanceof Config => $this->markIfShippingConfig($entity),
            default => null,
        };
    }

    // Only the two entries the block actually reads, so saving an unrelated setting costs nothing
    private function markIfShippingConfig(Config $config): void
    {
        if (in_array($config->getSlug(), self::CONFIG_SLUGS, true)) {
            $this->stale = true;
        }
    }
}
