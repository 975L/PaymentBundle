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

// A gift card is a means of payment: the invoice and the settled order state the gross total and its VAT, then the card, then the amount due
class GiftCardTotalsTest extends TestCase
{
    // On both documents, the gross total, then the VAT per rate, then the card, then the amount due
    public function testTheGiftCardComesAfterTheGrossTotalAndItsVat(): void
    {
        foreach (['templates/invoice/pdf.html.twig', 'templates/components/Basket/Items.html.twig'] as $path) {
            $template = $this->read($path);

            $this->assertStringContainsString('(paidByGiftCard ? basket.total + basket.shipping : basket.payable)', $template, $path);
            $total = strpos($template, "'label.total_incl_vat'|trans");
            $vat = strpos($template, "'label.including_vat_rate'|trans");
            $due = strpos($template, "'label.amount_due'|trans");
            $this->assertNotFalse($due, $path);
            $this->assertLessThan($vat, $total, $path);
            $this->assertLessThan($due, $vat, $path);
            $this->assertGreaterThan($vat, strrpos($template, '{% if paidByGiftCard %}'), $path);
        }
    }

    // While the basket can still change, the controller keeps one code line above the total: the reordering waits for a settled order
    public function testTheEditableBasketKeepsItsLiveOrder(): void
    {
        $this->assertStringContainsString("{% set paidByGiftCard = not editable and basket.discountKind == 'gift_card'", $this->read('templates/components/Basket/Items.html.twig'));
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $path);
    }
}
