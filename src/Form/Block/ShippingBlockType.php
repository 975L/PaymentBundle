<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form\Block;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ShippingBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Optional, as on the block kinds of the bundles plugging into this one: the page states its own heading, or none
            ->add('title', TextType::class, [
                'label' => 'label.block_title',
                'required' => false,
            ])
            // The only editorial line of the block - the amounts come from the configuration, so what is left to say is the area served, the delay, the carrier...
            ->add('note', TextType::class, [
                'label' => 'label.block_shipping_note',
                'required' => false,
            ])
        ;
    }

    // BlockType translates the embedded data form in the "ui" domain: without this, both labels above would be looked up there and rendered raw
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'payment']);
    }
}
