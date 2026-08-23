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

// The invitation an order left by a guest carries. Its conditions are what keep it from being shown to somebody it has nothing to offer - and none of them is an error anywhere: get one wrong and the wrong people are simply invited, on every order, for as long as nobody notices.
class AccountInvitationTest extends TestCase
{
    private function component(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/components/Basket/AccountInvitation.html.twig');
    }

    // An order already belonging to an account has nothing to gain, and a site with no one-click provider has nothing to offer: creating a password on the way out of a checkout is the friction this exists to avoid
    public function testItIsShownOnlyForAGuestOrderOnASiteOfferingOneClickSignIn(): void
    {
        $this->assertStringContainsString('{% if basket.user is null and oauth_login_providers() is not empty %}', $this->component());
    }

    // A sign-in flow started from a mailbox is fragile, and antispam scanners follow the links: the email invites back to the order's page, where the buttons are
    public function testTheEmailVariantLinksBackInsteadOfCarryingTheButtons(): void
    {
        $component = $this->component();

        $this->assertStringContainsString("<a href=\"{{ absolute_url(path('basket_paid'", $component);
        $this->assertStringContainsString('<twig:c975LConfig:Security:OAuthLogin redirect=', $component);
    }

    // The slot places the email variant, and nothing else places the page one
    public function testTheSlotRendersTheEmailVariant(): void
    {
        $slot = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/emails/slots/account_invitation.html.twig');

        $this->assertStringContainsString('c975LPayment:Basket:AccountInvitation', $slot);
        $this->assertStringContainsString(':link="true"', $slot);
    }

    // Rendered into an email too, where there is no visitor to ask about: reading app.user there would break the send rather than the page
    public function testItNeverReadsTheCurrentVisitor(): void
    {
        $this->assertStringNotContainsString('app.user', $this->component());
    }

    // Offered once the payment went through, and never during the checkout it would compete with
    public function testThePageShowsItOnlyOnAConfirmedOrderToASignedOutVisitor(): void
    {
        $display = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/basket/display.html.twig');

        $this->assertStringContainsString('{% if confirmed and app.user is null %}', $display);
        $this->assertStringContainsString('<twig:c975LPayment:Basket:AccountInvitation basket="{{ basket }}"/>', $display);
    }
}
