<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;

// The sale handed to Matomo. None of its ends raises an error when it drifts: an order pushed before it is confirmed counts a sale that never happened, a page forgetting the component counts none, and a dataset name the controller no longer reads sends nothing
class MatomoOrderTest extends TestCase
{
    // Same check as the tracker snippet, so a site not measuring its audience gets nothing pushed
    public function testTheComponentCarriesTheTrackerGuard(): void
    {
        $this->assertStringContainsString('{% if matomo_enabled() %}', $this->read('templates/components/Basket/MatomoOrder.html.twig'));
    }

    // The dataset name is the contract with the controller, which reads it off the element
    public function testTheDatasetNameMatchesWhatTheControllerReads(): void
    {
        $component = $this->read('templates/components/Basket/MatomoOrder.html.twig');

        $this->assertStringContainsString('data-controller="matomoOrder"', $component);
        $this->assertStringContainsString('data-matomo-order="{{ payment_matomo_order(basket)|json_encode }}"', $component);
        $this->assertStringContainsString('dataset.matomoOrder', $this->read('assets/js/matomo-order.js'));
        $this->assertStringContainsString("matomoOrder: () => import('./js/matomo-order.js'),", $this->read('assets/controllers.js'));
    }

    // Both pages a provider sends back to push the order, first thing inside their "confirmed" branch
    public function testBothReturnPagesPushTheOrderOnceConfirmed(): void
    {
        foreach (['templates/basket/display.html.twig', 'templates/basket/shared_paid.html.twig'] as $page) {
            $this->assertMatchesRegularExpression(
                '#\{% if confirmed %\}\s*<twig:c975LPayment:Basket:MatomoOrder basket="\{\{ basket \}\}"/>#',
                $this->read($page),
                \sprintf('%s no longer hands the order to Matomo once it is confirmed.', $page)
            );
        }
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
