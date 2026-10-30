<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Contract\InAppStoreInterface;
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Twig\InAppStoreExtension;
use PHPUnit\Framework\TestCase;

// What a page may say about selling in an app, which an app identifier alone does not answer
class InAppStoreExtensionTest extends TestCase
{
    public function testOnlyTheStoresFullySetUpAreListed(): void
    {
        $registry = new InAppStoreRegistry([$this->store('apple', false), $this->store('google-play', true)]);

        $this->assertSame(['google-play'], new InAppStoreExtension($registry)->stores());
    }

    // A site selling in no app lists nothing, and the legal units keyed on it draw nothing
    public function testNoStoreSetUpListsNothing(): void
    {
        $registry = new InAppStoreRegistry([$this->store('apple', false), $this->store('google-play', false)]);

        $this->assertSame([], new InAppStoreExtension($registry)->stores());
    }

    // A store answering to the slug, set up or not
    private function store(string $slug, bool $configured): InAppStoreInterface
    {
        $store = $this->createStub(InAppStoreInterface::class);
        $store->method('getSlug')->willReturn($slug);
        $store->method('isConfigured')->willReturn($configured);

        return $store;
    }
}
