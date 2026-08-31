<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\ShippingRate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// One weight tier of a zone, edited in the collection its zone's screen shows (see ShippingZoneCrudController)
class ShippingRateType extends AbstractType
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('maxWeight', IntegerType::class, [
                'required' => false,
                'label' => 'label.shipping_max_weight',
                'help' => 'label.shipping_max_weight_help',
                'attr' => ['min' => 0, 'step' => 1],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'label.shipping_price',
                'help' => 'label.shipping_price_help',
                // Stored in cents like every other amount of this bundle, typed in the currency the shop charges in
                'divisor' => 100,
                'currency' => $this->currency(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShippingRate::class,
            'translation_domain' => 'payment',
        ]);
    }

    // The shop's own currency, which is what the tiers of this screen are typed in
    private function currency(): string
    {
        $currency = strtoupper(trim((string) $this->configService->get('shop-currency')));

        return '' === $currency ? 'EUR' : $currency;
    }
}
