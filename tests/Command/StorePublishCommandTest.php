<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Command;

use c975L\PaymentBundle\Command\StorePublishCommand;
use c975L\PaymentBundle\Service\InAppCatalogPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The console entry point to InAppCatalogPublisher: a store refusing a product must fail the run, not scroll past
class StorePublishCommandTest extends TestCase
{
    public function testEveryPublicationIsListed(): void
    {
        $tester = $this->tester([['product' => 'back.a', 'store' => 'apple', 'error' => null], ['product' => 'back.a', 'store' => 'google', 'error' => null]]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('2 publication(s) done', $tester->getDisplay());
    }

    public function testAFailedPublicationFailsTheRun(): void
    {
        $tester = $this->tester([['product' => 'back.a', 'store' => 'apple', 'error' => 'No price point']]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No price point', $tester->getDisplay());
    }

    // No product, or no store holding the catalogue keys: said, not failed
    public function testNothingToPublishIsAWarning(): void
    {
        $tester = $this->tester([]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['product' => 'back.z']));
        $this->assertStringContainsString('Nothing was published', $tester->getDisplay());
    }

    /** @param list<array{product: string, store: string, error: ?string}> $report */
    private function tester(array $report): CommandTester
    {
        $publisher = $this->createStub(InAppCatalogPublisher::class);
        $publisher->method('publish')->willReturn($report);

        return new CommandTester(new StorePublishCommand($publisher));
    }
}
