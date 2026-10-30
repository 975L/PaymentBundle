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
use c975L\PaymentBundle\Entity\StorePurchase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// The purchases made in the app, read only: the store is the merchant of record and writes them, the screen is there to answer a buyer - what they bought, when, and whether a refund took it back
class StorePurchaseCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return StorePurchase::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('store')
                ->setLabel(t('label.store', [], 'payment')),
            TextField::new('productId')
                ->setLabel(t('label.product_id', [], 'payment')),
            TextField::new('holder')
                ->setLabel(t('label.holder', [], 'payment')),
            TextField::new('transactionId')
                ->setLabel(t('label.transaction_id', [], 'payment')),
            TextField::new('environment')
                ->setLabel(t('label.environment', [], 'payment')),
            BooleanField::new('isRefunded')
                ->setLabel(t('label.is_refunded', [], 'payment'))
                ->renderAsSwitch(false),
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'payment')),
            DateTimeField::new('refunded')
                ->setLabel(t('label.refunded_at', [], 'payment'))
                ->onlyOnDetail(),
        ];
    }

    // Nothing to create, edit or delete: a row is what verifying a purchase leaves, and deleting one would let a refund go unnoticed
    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
        EasyAdminActionHelper::iconOnlyDefaults($actions, $this->translator, [Action::DETAIL]);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::DETAIL, $role)
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->setDefaultSort(['creation' => 'DESC'])
            ->overrideTemplate('crud/index', '@c975LPayment/management/store_purchase_crud_index.html.twig')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('store')
            ->add(BooleanFilter::new('isRefunded'))
        ;
    }
}
