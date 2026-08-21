<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form;

use c975L\PaymentBundle\Entity\Basket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CoordinatesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'label.email',
                'required' => true,
                'attr' => [
                    'placeholder' => 'placeholder.email',
                ],
            ])
        ;
        // Shipping address if not full digital
        if (1 !== $options['data']->getContentFlags()) {
            $builder
                ->add('name', TextType::class, [
                    'label' => 'label.name',
                    'required' => true,
                    'attr' => [
                        'placeholder' => 'placeholder.name',
                    ],
                ])
                ->add('address', TextType::class, [
                    'label' => 'label.address',
                    'attr' => [
                        'placeholder' => 'placeholder.address',
                    ],
                ])
                ->add('city', TextType::class, [
                    'label' => 'label.city',
                    'attr' => [
                        'placeholder' => 'placeholder.city',
                    ],
                ])
                ->add('zip', TextType::class, [
                    'label' => 'label.zip',
                    'attr' => [
                        'placeholder' => 'placeholder.zip',
                    ],
                ])
                ->add('country', TextType::class, [
                    'label' => 'label.country',
                    'attr' => [
                        'placeholder' => 'placeholder.country',
                    ],
                ])
                ->add('message', TextareaType::class, [
                    'required' => false,
                    'label' => 'label.message',
                    'attr' => [
                        'placeholder' => 'placeholder.message',
                    ],
                ])
            ;
        }

        // Message if crowdfunding
        $items = $options['data']->getItems();
        if (isset($items['crowdfunding'])) {
            $builder
                ->add('contribution', FormType::class, [
                    'label' => 'label.contributor_message',
                    'required' => false,
                    'mapped' => false,
                    'label_attr' => [
                        'class' => 'form-section-title',
                    ],
                ])
                ->add('helpText', FormType::class, [
                    'label' => false,
                    'required' => false,
                    'mapped' => false,
                    'help' => 'label.contributor_help',
                    'help_attr' => [
                        'class' => 'alert alert-info',
                    ],
                ])
                ->add('contributorMessage', TextareaType::class, [
                    'label' => 'label.support_message',
                    'required' => false,
                    'mapped' => false,
                    'attr' => [
                        'placeholder' => 'placeholder.support_message',
                        'rows' => 3,
                    ],
                ])
                ->add('contributorName', TextType::class, [
                    'label' => 'label.signature',
                    'required' => false,
                    'mapped' => false,
                    'attr' => [
                        'placeholder' => 'placeholder.signature',
                    ],
                ])
            ;
        }

        // Checkboxes
        $builder
            // GDPR
            ->add('gdpr', CheckboxType::class, [
                'label' => 'text.gdpr',
                'translation_domain' => 'site',
                'required' => true,
                'mapped' => false,
            ])
            // Terms of use
            ->add('cgu', CheckboxType::class, [
                'label' => $options['config']['touUrl'],
                'label_html' => true,
                'required' => true,
                'mapped' => false,
            ])
            // Terms of sales
            ->add('cgv', CheckboxType::class, [
                'label' => $options['config']['tosUrl'],
                'label_html' => true,
                'required' => true,
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Basket::class,
            'intention' => 'basket',
            'translation_domain' => 'payment',
            'allow_extra_fields' => true,
        ]);

        $resolver->setRequired('config');
    }
}
