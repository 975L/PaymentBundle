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
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CoordinatesType extends AbstractType
{
    // A declaration of fields, one block per field: its length says how much the form asks for, not how much the method decides
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'label.email',
                'required' => true,
            ])
        ;
        // Shipping address if not full digital
        if (1 !== $options['data']->getContentFlags()) {
            $builder
                ->add('name', TextType::class, [
                    'label' => 'label.name',
                    'required' => true,
                ])
                ->add('address', TextType::class, [
                    'label' => 'label.address',
                ])
                ->add('city', TextType::class, [
                    'label' => 'label.city',
                ])
                ->add('zip', TextType::class, [
                    'label' => 'label.zip',
                ])
                // A list and not a free text: the delivery is priced on the country, and a zone naming "FR" recognises nothing in "france", "France" or "FRANCE". CountryType stores the ISO 3166-1 alpha-2 code and shows the customer the name in their own language
                ->add('country', CountryType::class, [
                    'label' => 'label.country',
                ])
                ->add('message', TextareaType::class, [
                    'required' => false,
                    'label' => 'label.message',
                ])
            ;
        }

        // Who the gift cards are for. A card is bought for somebody else by definition, and the address asked for here is what lets them open it without an account of their own - left blank, the buyer forwards the link themselves
        if (0 !== ($options['data']->getContentFlags() & Basket::CONTENT_FLAG_GIFT_CARD)) {
            $builder
                ->add('giftCardRecipientEmail', EmailType::class, [
                    'label' => 'label.gift_card_recipient_email',
                    'required' => false,
                    'help' => 'description.gift_card_recipient_email',
                ])
                ->add('giftCardRecipientMessage', TextareaType::class, [
                    'label' => 'label.gift_card_recipient_message',
                    'required' => false,
                    'attr' => [
                        'rows' => 3,
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
                        'rows' => 3,
                    ],
                ])
                ->add('contributorName', TextType::class, [
                    'label' => 'label.signature',
                    'required' => false,
                    'mapped' => false,
                ])
            ;
        }

        // Invoice made out to a business, optional: the postal address a business invoice has to carry is asked here when the order is entirely digital, and required once a company is given (see validateCompany())
        $builder
            ->add('business', FormType::class, [
                'label' => 'label.business_invoice',
                'required' => false,
                'mapped' => false,
                'label_attr' => [
                    'class' => 'form-section-title',
                ],
            ])
            ->add('company', TextType::class, [
                'label' => 'label.company',
                'required' => false,
            ])
            ->add('vatNumber', TextType::class, [
                'label' => 'label.vat_number',
                'required' => false,
                'help' => 'description.vat_number',
            ])
        ;
        if (1 === $options['data']->getContentFlags()) {
            $builder
                ->add('address', TextType::class, [
                    'label' => 'label.address',
                    'required' => false,
                ])
                ->add('zip', TextType::class, [
                    'label' => 'label.zip',
                    'required' => false,
                ])
                ->add('city', TextType::class, [
                    'label' => 'label.city',
                    'required' => false,
                ])
                ->add('country', CountryType::class, [
                    'label' => 'label.country',
                    'required' => false,
                ])
            ;
        }
        $builder->addEventListener(FormEvents::SUBMIT, fn (FormEvent $event) => $this->forgetBusiness($event->getData()));

        // Checkboxes
        $builder
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
            'constraints' => [new Callback($this->validateCompany(...))],
        ]);

        $resolver->setRequired('config');
    }

    // An emptied company takes the rest of the prefilled business details with it, so a buyer ordering for themselves this time isn't invoiced with a VAT number or, on a digital order, an address that belong to their company
    public function forgetBusiness(Basket $basket): void
    {
        if (null !== $basket->getCompany()) {
            return;
        }

        $basket->setVatNumber(null);
        if (1 === $basket->getContentFlags()) {
            $basket
                ->setAddress(null)
                ->setZip(null)
                ->setCity(null)
                ->setCountry(null);
        }
    }

    // A VAT number starts with its country's two letters, and a business invoice carries the business' postal address, which a digital order doesn't otherwise ask for
    public function validateCompany(Basket $basket, ExecutionContextInterface $context): void
    {
        if (null !== $basket->getVatNumber() && 1 !== preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $basket->getVatNumber())) {
            $context->buildViolation('text.vat_number_invalid')
                ->setTranslationDomain('payment')
                ->atPath('vatNumber')
                ->addViolation();
        }

        if (null === $basket->getCompany()) {
            return;
        }

        foreach (['address' => $basket->getAddress(), 'zip' => $basket->getZip(), 'city' => $basket->getCity(), 'country' => $basket->getCountry()] as $field => $value) {
            if (null === $value || '' === trim($value)) {
                $context->buildViolation('text.company_address_required')
                    ->setTranslationDomain('payment')
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }
}
