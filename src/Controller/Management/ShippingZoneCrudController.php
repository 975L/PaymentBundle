<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\ShippingZone;
use c975L\PaymentBundle\Form\ShippingRateType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// The delivery grid: a zone groups the countries posted at one tariff, and carries its weight tiers with it. One screen and not two, a tier having no meaning away from its zone
class ShippingZoneCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ShippingZone::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->setDefaultSort(['name' => 'ASC'])
            ->setEntityLabelInSingular(t('label.shipping_zone', [], 'payment'))
            ->setEntityLabelInPlural(t('label.shipping_zones', [], 'payment'))
            ->overrideTemplate('crud/index', '@c975LPayment/management/shipping_zone_crud_index.html.twig')
        ;
    }

    // Icon-only row buttons, as everywhere else in the back office
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name')
                ->setLabel(t('label.shipping_zone_name', [], 'payment'))
                ->setHelp(t('help.shipping_zone_name', [], 'payment')),
            // The ISO codes the order carries, picked from a list rather than typed: a zone naming "FR" recognises nothing in "France"
            ChoiceField::new('countries')
                ->setLabel(t('label.shipping_countries', [], 'payment'))
                ->setHelp(t('help.shipping_countries', [], 'payment'))
                ->setChoices(array_flip(Countries::getNames()))
                ->allowMultipleChoices()
                ->renderExpanded(false),
            CollectionField::new('rates')
                ->setLabel(t('label.shipping_rates', [], 'payment'))
                ->setHelp(t('help.shipping_rates', [], 'payment'))
                ->hideOnIndex()
                ->setEntryType(ShippingRateType::class)
                ->allowAdd()
                ->allowDelete(),
            BooleanField::new('active')
                ->setLabel(t('label.active', [], 'payment')),
        ];
    }
}
