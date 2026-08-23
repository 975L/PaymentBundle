<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Provider;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\PaymentLinkItem;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Provider\PaymentLinkItemProvider;
use PHPUnit\Framework\TestCase;

// The one item this bundle sells for itself: a line the shop types instead of picking it out of a catalogue
class PaymentLinkItemProviderTest extends TestCase
{
    // The shape every other kind hands over, so the payer's page, the checkout and the confirmation email read this line as they read any other
    public function testTheLineIsBuiltInTheShapeEveryBasketEntryHas(): void
    {
        $line = $this->provider()->toBasketData(new PaymentLinkItem('Acompte chantier', 25000, 'Solde à la livraison'), 1);

        $this->assertSame('Acompte chantier', $line['item']['title']);
        $this->assertSame('Solde à la livraison', $line['item']['description']);
        $this->assertSame(25000, $line['item']['price']);
        $this->assertSame('EUR', $line['item']['currency']);
        $this->assertSame(25000, $line['total']);
        $this->assertSame(1, $line['quantity']);
        $this->assertSame(PaymentLinkItemProvider::KIND, $line['type']);
    }

    // Prices are held VAT included everywhere here, so the tax is taken out of what was typed rather than added to it
    public function testTheVatIsTakenOutOfTheAmountAtTheConfiguredRate(): void
    {
        $line = $this->provider('20')->toBasketData(new PaymentLinkItem('Réparation', 12000), 1);

        $this->assertSame(20.0, $line['item']['vat']);
        $this->assertSame(2000, $line['totalVat']);
    }

    // A shop charging none sets nothing, and the line is worth what it says
    public function testAShopChargingNoVatHasNoneTakenOut(): void
    {
        $line = $this->provider('0')->toBasketData(new PaymentLinkItem('Réparation', 12000), 1);

        $this->assertSame(0.0, $line['item']['vat']);
        $this->assertSame(0, $line['totalVat']);
    }

    // Hanging under no catalogue entry: what tells the components to draw the label alone, with no link to a page that was never declared and no picture
    public function testTheLineNamesNoParentToLinkTo(): void
    {
        $line = $this->provider()->toBasketData(new PaymentLinkItem('Prestation', 5000), 1);

        $this->assertSame('', $line['parent']['title']);
        $this->assertNull($line['parent']['slug']);
        $this->assertFalse($line['parent']['image']);
        $this->assertNull($line['item']['media']);
        $this->assertSame(0, $line['item']['limitedQuantity']);
    }

    // Counted as a service and never as goods: PaymentStatusProvider lists the physical orders left to ship, and a link settled would otherwise sit in it for good
    public function testALinkIsAServiceAndNeverSomethingToShip(): void
    {
        $flags = $this->provider()->getContentFlags([]);

        $this->assertSame(Basket::CONTENT_FLAG_SERVICE, $flags);
        $this->assertSame(0, $flags & Basket::CONTENT_FLAG_PHYSICAL);
    }

    // Minted in the back-office and nowhere else: a visitor able to resolve one would post themselves a line worth what they choose
    public function testNothingOfThisKindCanBeAddedFromTheFront(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->findItem(1));
        $this->assertNotNull($provider->validateAddition(new PaymentLinkItem('Prestation', 5000), 1));
    }

    // A typed amount runs out of no stock and expires on no date
    public function testTheOrderIsAlwaysStillPlaceable(): void
    {
        $this->assertNull($this->provider()->validateCheckout(new Basket(), []));
    }

    private function provider(string $vatRate = '0'): PaymentLinkItemProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'payment-link-vat-rate' => $vatRate,
            'shop-currency' => 'EUR',
            default => '',
        });

        return new PaymentLinkItemProvider($configService);
    }
}
