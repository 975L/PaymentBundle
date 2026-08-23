<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Form\Block;

use c975L\PaymentBundle\Form\Block\ShippingBlockType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The one kind this bundle registers, checked on what a block editor depends on: which field is required, and in which domain the labels are looked up
class ShippingBlockTypeTest extends TestCase
{
    /** @return array<string, array{type: ?string, options: array<string, mixed>}> */
    private function build(ShippingBlockType $type): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $formType = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $formType, 'options' => $options];

            return $builder;
        });

        $type->buildForm($builder, []);

        return $added;
    }

    // The amounts come from the configuration, so the block asks for nothing an editor has to fill in
    public function testTheHeadingAndTheNoteAreBothOptional(): void
    {
        $added = $this->build(new ShippingBlockType());

        $this->assertSame(TextType::class, $added['title']['type']);
        $this->assertFalse($added['title']['options']['required']);
        $this->assertSame(TextType::class, $added['note']['type']);
        $this->assertFalse($added['note']['options']['required']);
    }

    // BlockType translates the embedded data form in the "ui" domain: a kind forgetting to say otherwise renders every one of its labels raw
    public function testTheLabelsAreLookedUpInThePaymentDomain(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['translation_domain' => 'ui']);
        new ShippingBlockType()->configureOptions($resolver);

        $this->assertSame('payment', $resolver->resolve()['translation_domain']);
    }
}
