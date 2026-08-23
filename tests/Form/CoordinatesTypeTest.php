<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Form;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Form\CoordinatesType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

// The checkout's own form, checked on the two things a mistake here costs: an address asked of an order that has none to deliver, and a consent that is not one
class CoordinatesTypeTest extends TestCase
{
    /** @return array<string, array{type: ?string, options: array<string, mixed>}> */
    private function build(int $contentFlags): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $formType = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $formType, 'options' => $options];

            return $builder;
        });

        $basket = new Basket();
        $basket->setContentFlags($contentFlags);

        new CoordinatesType()->buildForm($builder, [
            'data' => $basket,
            'config' => ['touUrl' => 'tou', 'tosUrl' => 'tos'],
        ]);

        return $added;
    }

    // An order of files alone is delivered by email: asking for a postal address is asking for what is never used, and keeping it is keeping what nothing justifies
    public function testAnOrderOfFilesAloneIsNeverAskedForAnAddress(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_DIGITAL);

        $this->assertArrayHasKey('email', $added);
        $this->assertArrayNotHasKey('name', $added);
        $this->assertArrayNotHasKey('address', $added);
    }

    // Anything to ship needs somewhere to ship it to
    public function testAnOrderToShipIsAskedWhereToSendIt(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertArrayHasKey('address', $added);
        $this->assertArrayHasKey('city', $added);
        $this->assertTrue($added['name']['options']['required']);
    }

    // An abandoned basket is no concluded sale, so nothing but consent allows the reminder: a box ticked in advance, or one that cannot be left alone, is not a consent
    public function testTheReminderConsentIsTheOneBoxThatIsNotRequired(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertSame(CheckboxType::class, $added['reminderConsent']['type']);
        $this->assertFalse($added['reminderConsent']['options']['required']);
        $this->assertArrayNotHasKey('data', $added['reminderConsent']['options']);
    }

    // The reminder goes out days later with nobody around to be asked again, so the answer is the basket's own column and not an unmapped box
    public function testTheReminderConsentIsCarriedByTheBasket(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertArrayNotHasKey('mapped', $added['reminderConsent']['options']);
        $this->assertFalse($added['gdpr']['options']['mapped']);
        $this->assertFalse($added['cgv']['options']['mapped']);
    }
}
