<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use Doctrine\ORM\Mapping\JoinColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserForeignKeyTest extends TestCase
{
    public static function provideEntities(): iterable
    {
        yield 'basket' => [Basket::class];
        yield 'payment' => [Payment::class];
    }

    // Back to the default RESTRICT, the foreign key would block deleting any account that ever paid
    #[DataProvider('provideEntities')]
    public function testDeletingTheAccountDetachesTheRecord(string $class): void
    {
        $attributes = new \ReflectionProperty($class, 'user')->getAttributes(JoinColumn::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('SET NULL', $attributes[0]->newInstance()->onDelete);
    }
}
