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

// What the checkout owes its customer about their data: an information line and never a consent box, the processing resting on the contract itself. Read off the template, the page it lives on booting a whole basket to render.
class CheckoutGdprInformationTest extends TestCase
{
    public function testTheCheckoutPrintsTheInformationLine(): void
    {
        $this->assertStringContainsString(
            "{{ 'text.gdpr_information'|trans({'%privacyUrl%': config('url-privacy-policy')}, 'ui')|raw }}",
            $this->validation(),
            'The checkout no longer states what the customer\'s details are used for.'
        );
    }

    // The "ui" catalog and not "site": this bundle depends on core-bundle alone, and a shop running without SiteBundle would print the raw key
    public function testTheLineIsReadFromTheCatalogThisBundleDependsOn(): void
    {
        $this->assertStringNotContainsString("'text.gdpr_information'|trans({'%privacyUrl%': config('url-privacy-policy')}, 'site')", $this->validation());
    }

    // A link to a page the admin never named would be a dead one, so the whole block is skipped rather than shown broken
    public function testTheLineIsGuardedByItsSetting(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\{% if config\\('url-privacy-policy'\\) %\\}.*?text\\.gdpr_information.*?\\{% endif %\\}/s",
            $this->validation(),
            'The information line is no longer behind "url-privacy-policy", so a shop that never filled it in shows a dead link.'
        );
    }

    // Answering a visitor who ordered rests on the contract, not on a consent - a box they could not refuse without giving up their order was never one
    public function testTheCheckoutAsksForNoGdprConsent(): void
    {
        $coordinates = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Form/CoordinatesType.php');

        $this->assertStringNotContainsString("->add('gdpr'", $coordinates);
        $this->assertStringNotContainsString("->add('reminderConsent'", $coordinates);
    }

    private function validation(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/components/Basket/Validation.html.twig');
    }
}
