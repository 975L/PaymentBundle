<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The sheet of address labels, whose whole job is to line up with a box of pre-cut labels.
 *
 * Nothing here renders a PDF, so what is guarded is the arithmetic the sheet stands on: ten labels of 105 by 57 mm
 * on an A4, laid out the one way both engines paginate. Every rule below was got wrong once and printed askew.
 */
class ShippingLabelsSheetTest extends TestCase
{
    private const string TEMPLATE = 'templates/management/shipping_labels.html.twig';

    // A4 is 210 wide, so two columns of 105 leave nothing at the sides: the page must claim no side margin of its own
    public function testTheSheetTakesTheWholeWidthOfThePage(): void
    {
        $template = $this->read();

        $this->assertStringContainsString('@page { size: A4; margin: 6mm 0; }', $template);
        $this->assertStringContainsString('width: 210mm;', $template);
        $this->assertStringContainsString('width: 105mm;', $template);
    }

    /**
     * 45 mm of content, 8 above and 4 below: 57, which is where the sheet is cut.
     *
     * Written as content plus padding and not as a height of 57 with padding inside it, "box-sizing" being one of
     * the things dompdf and WeasyPrint read differently - and a cell 12 mm too tall is four labels to a page
     * instead of five, every one of them off the paper.
     */
    public function testALabelIsExactlyAsTallAsTheOneItIsStuckOn(): void
    {
        $template = $this->read();

        $this->assertStringContainsString('height: 45mm;', $template);
        $this->assertStringContainsString('padding: 8mm 8mm 4mm;', $template);
    }

    // A table row and not a floated box: floats are where the two engines disagree, and dompdf packs a sheet of them onto a single page
    public function testTheSheetIsATableSoBothEnginesBreakItIntoPages(): void
    {
        $template = $this->read();

        $this->assertStringContainsString('|batch(2)', $template);
        $this->assertStringNotContainsString('float:', $template);
    }

    // A last row short of one leaves the other half of the sheet blank rather than stretching one label across it
    public function testALastRowHoldingOneLabelIsPaddedOut(): void
    {
        $this->assertStringContainsString('{% if pair|length == 1 %}<td></td>{% endif %}', $this->read());
    }

    private function read(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . self::TEMPLATE);
    }
}
