<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

class ImmutableDatesTest extends TestCase
{
    private const array MUTABLE = [Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE, Types::TIME_MUTABLE];

    private const array IMMUTABLE = [Types::DATE_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE, Types::TIME_IMMUTABLE];

    // Every date column of the bundle, with its declared Doctrine type
    /** @return list<array{\ReflectionClass<object>, \ReflectionProperty, ?string}> */
    private function dateColumns(): array
    {
        $columns = [];
        foreach (glob(__DIR__ . '/../../src/Entity/*.php') ?: [] as $file) {
            $class = new \ReflectionClass('c975L\\PaymentBundle\\Entity\\' . basename($file, '.php'));
            foreach ($class->getProperties() as $property) {
                $type = (string) $property->getType();
                foreach ($property->getAttributes(Column::class) as $attribute) {
                    $columnType = $attribute->newInstance()->type;
                    if (str_contains($type, 'DateTime') || in_array($columnType, [...self::MUTABLE, ...self::IMMUTABLE], true)) {
                        $columns[] = [$class, $property, $columnType];
                    }
                }
            }
        }

        return $columns;
    }

    // No date column is left mutable, Doctrine DBAL deprecating those types
    public function testNoDateColumnIsMutable(): void
    {
        $columns = $this->dateColumns();
        $this->assertNotEmpty($columns);

        foreach ($columns as [$class, $property, $columnType]) {
            $label = $class->getShortName() . '::$' . $property->getName();
            // No type given lets Doctrine infer it from the property's
            $this->assertContains($columnType, [null, ...self::IMMUTABLE], $label);
            $this->assertStringEndsWith('DateTimeImmutable', (string) $property->getType(), $label);
        }
    }

    // A mutable date handed to a setter accepting any date is stored immutable, same instant
    public function testSettersStoreAMutableDateAsImmutable(): void
    {
        foreach ($this->dateColumns() as [$class, $property]) {
            $name = ucfirst($property->getName());
            $setter = $class->hasMethod('set' . $name) ? $class->getMethod('set' . $name) : null;
            if ($class->isAbstract() || !str_ends_with((string) $setter?->getParameters()[0]->getType(), 'DateTimeInterface')) {
                continue;
            }
            $entity = $class->newInstanceWithoutConstructor();
            $entity->{'set' . $name}(new \DateTime('2026-01-15 10:00:00'));
            $date = $entity->{'get' . $name}();

            $this->assertInstanceOf(\DateTimeImmutable::class, $date, $class->getShortName() . '::$' . $property->getName());
            $this->assertSame('2026-01-15', $date->format('Y-m-d'));
        }
    }
}
