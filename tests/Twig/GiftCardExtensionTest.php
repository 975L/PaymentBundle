<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Twig;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Service\GiftCardService;
use c975L\PaymentBundle\Twig\GiftCardExtension;
use PHPUnit\Framework\TestCase;
use Twig\Attribute\AsTwigFunction;

// The name the templates call is written in an attribute and nowhere else, so it is what a test has to read back: renaming the method silently leaves a buyer's cards off their order page
class GiftCardExtensionTest extends TestCase
{
    // The order page and the confirmation email both call payment_gift_cards()
    public function testTheFunctionIsRegisteredUnderTheNameTheTemplatesCall(): void
    {
        $attributes = new \ReflectionMethod(GiftCardExtension::class, 'giftCards')->getAttributes(AsTwigFunction::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('payment_gift_cards', $attributes[0]->getArguments()[0]);
    }

    // The cards are rows the delivery wrote, read back through the service rather than carried across the checkout
    public function testTheCardsAreTheServicesOwn(): void
    {
        $basket = new Basket();
        $cards = [new GiftCard()];

        $service = $this->createMock(GiftCardService::class);
        $service->expects($this->once())
            ->method('findIssuedBy')
            ->with($basket)
            ->willReturn($cards);

        $this->assertSame($cards, new GiftCardExtension($service)->giftCards($basket));
    }
}
