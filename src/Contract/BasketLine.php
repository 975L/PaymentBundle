<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Contract;

/**
 * The shape one line of a basket is written in, and the one place an older shape is brought up to it.
 *
 * An open basket needs none of this - its lines are read again off their providers on every change (see
 * BasketService::refreshItems()). An order does: it is kept the ten years the accounting obligation asks for,
 * it is never rebuilt, and the invoice, the emails and the tracking page still have to read it years later.
 */
final class BasketLine
{
    /**
     * The shape the lines are written in today.
     *
     * A counter, not a date and not a semver, bumped only when a change cannot be read off the data itself: a key
     * renamed, a unit changed, a meaning moved. A key simply added never bumps it - normalize() fills it with the
     * default the code already read it with, and "absent" is answer enough.
     *
     * 1: the shape the engine was rewritten with
     */
    public const int VERSION = 1;

    // Marks a line as written in the current shape, which is what tells normalize() what it is looking at years later
    public static function stamp(array $line): array
    {
        $line['v'] = self::VERSION;

        return $line;
    }

    /**
     * Brings a line up to the current shape, so nothing that reads it has to ask.
     *
     * What is filled here are the keys added over time, each with the very default the code used to read it with,
     * so an old order goes on saying exactly what it always said. Nothing ambiguous has happened yet and so nothing
     * reads the number - the first migration that cannot be read off the data itself branches here on $line['v'] ?? 1.
     */
    public static function normalize(array $line): array
    {
        $line['totalVat'] ??= 0;

        $line['item'] = ($line['item'] ?? []) + [
            'vat' => 0.0,
            'description' => '',
            'media' => null,
            'slug' => null,
            'limitedQuantity' => 0,
            'orderedQuantity' => 0,
        ];

        $line['parent'] = ($line['parent'] ?? []) + [
            'title' => '',
            'slug' => null,
            'image' => false,
        ];

        // "service", "file" and "requiresShipping" are left out on purpose: Item:Type tells a line that names one apart from a line that names none, and writing a default here would answer a question the provider never asked
        return $line;
    }
}
