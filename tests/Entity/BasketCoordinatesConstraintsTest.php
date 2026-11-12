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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// The coordinates are checked on the server against their columns: a value too long would only fail at the flush, with a 500 at the payment
class BasketCoordinatesConstraintsTest extends TestCase
{
    /** @return iterable<array{string, int}> */
    public static function columns(): iterable
    {
        yield ['email', 100];
        yield ['name', 100];
        yield ['address', 100];
        yield ['city', 100];
        yield ['zip', 10];
        yield ['message', 255];
    }

    // One character above its column is refused, the column's length accepted
    #[DataProvider('columns')]
    public function testAValueLongerThanItsColumnIsRefused(string $property, int $length): void
    {
        $basket = new Basket();
        $setter = 'set' . ucfirst($property);
        $suffix = 'email' === $property ? '@example.org' : '';

        $basket->$setter(str_repeat('a', $length - \strlen($suffix)) . $suffix);
        $this->assertCount(0, $this->validator()->validateProperty($basket, $property), $property);

        $basket->$setter(str_repeat('a', $length + 1 - \strlen($suffix)) . $suffix);
        $this->assertGreaterThan(0, \count($this->validator()->validateProperty($basket, $property)), $property);
    }

    // An order names somebody to confirm it to: an empty or malformed email is refused
    public function testTheEmailIsRequiredAndWellFormed(): void
    {
        $this->assertGreaterThan(0, \count($this->validator()->validateProperty(new Basket(), 'email')));
        $this->assertGreaterThan(0, \count($this->validator()->validateProperty(new Basket()->setEmail('not-an-email'), 'email')));
        $this->assertCount(0, $this->validator()->validateProperty(new Basket()->setEmail('buyer@example.org'), 'email'));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
