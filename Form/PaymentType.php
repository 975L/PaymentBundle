<?php
/*
 * (c) 2018: 975l <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PaymentType extends AbstractType
{
    //Builds the form
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('description', TextType::class, array(
                'label' => 'label.description',
                'required' => true,
                'attr' => array(
                    'placeholder' => 'label.description',
                )))
            ->add('amount', MoneyType::class, array(
                'label' => 'label.amount',
                'currency' => $options['data']->getCurrency(),
                'required' => true,
                'attr' => array(
                    'placeholder' => 'label.amount',
                )))
            ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'c975L\PaymentBundle\Entity\Payment',
            'intention'  => 'paymentForm',
            'translation_domain' => 'payment',
        ));
    }
}