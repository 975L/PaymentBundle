<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Form;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\ShippingRate;
use c975L\PaymentBundle\Form\ShippingRateType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// One weight tier of a zone, checked on the two things a mistake here costs: a ceiling an admin cannot leave open, and an amount typed in a currency the shop does not charge in
class ShippingRateTypeTest extends TestCase
{
    /** @return array<string, array{type: ?string, options: array<string, mixed>}> */
    private function build(?string $currency): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $formType = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $formType, 'options' => $options];

            return $builder;
        });

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($currency);

        new ShippingRateType($configService)->buildForm($builder, []);

        return $added;
    }

    // The boundless tier is the one that catches everything above the others, so its ceiling has to be leavable empty
    public function testTheCeilingIsOptionalSoTheBoundlessTierCanBeWritten(): void
    {
        $added = $this->build('EUR');

        $this->assertSame(IntegerType::class, $added['maxWeight']['type']);
        $this->assertFalse($added['maxWeight']['options']['required']);
    }

    // Amounts are held in cents throughout this bundle, and typed in the currency the shop charges in
    public function testThePriceIsTypedInTheShopsCurrencyAndHeldInCents(): void
    {
        $added = $this->build('usd');

        $this->assertSame(MoneyType::class, $added['price']['type']);
        $this->assertSame(100, $added['price']['options']['divisor']);
        $this->assertSame('USD', $added['price']['options']['currency']);
    }

    // A shop that has not said what it charges in still has a screen it can type an amount on
    public function testAShopWithNoCurrencySetFallsBackOnTheEuro(): void
    {
        $this->assertSame('EUR', $this->build(null)['price']['options']['currency']);
        $this->assertSame('EUR', $this->build(' ')['price']['options']['currency']);
    }

    public function testTheTierIsEditedAsAShippingRateAndTranslatedInThisBundlesDomain(): void
    {
        $resolver = new OptionsResolver();
        $configService = $this->createStub(ConfigServiceInterface::class);

        new ShippingRateType($configService)->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertSame(ShippingRate::class, $options['data_class']);
        $this->assertSame('payment', $options['translation_domain']);
    }
}
