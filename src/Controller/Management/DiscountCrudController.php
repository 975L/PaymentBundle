<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Discount;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

use function Symfony\Component\Translation\t;

// The one of the two codes a shop writes by hand. A gift card is not editable here and has no screen of its own to create one: it is money somebody paid for, so it is issued by the order that bought it (see GiftCardCrudController)
class DiscountCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly PaymentTestModeInterface $testMode,
    ) {
    }

    // Stamped when the row is written and never offered as a field: a code is born in the mode the shop is charging in, and moving it to the other one afterwards would make a rehearsal's code spendable for real
    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof Discount) {
            $entityInstance->setTestMode($this->testMode->isEnabled());
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public static function getEntityFqcn(): string
    {
        return Discount::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->setDefaultSort(['creation' => 'DESC'])
            ->setEntityLabelInSingular(t('label.discount', [], 'payment'))
            ->setEntityLabelInPlural(t('label.discounts', [], 'payment'))
            ->overrideTemplate('crud/index', '@c975LPayment/management/discount_crud_index.html.twig')
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('code')
                ->setLabel(t('label.code_promotional', [], 'payment'))
                ->setHelp(t('help.discount_code', [], 'payment')),
            ChoiceField::new('kind')
                ->setLabel(t('label.discount_kind', [], 'payment'))
                // setTranslatableChoices(), not setChoices(): a plain choice key is resolved in the CRUD's own translation domain, which is the dashboard's ("config"), never "payment" - a t() object carries its domain with it, on the form as on the index
                ->setTranslatableChoices([
                    Discount::KIND_PERCENTAGE => t('label.discount_percentage', [], 'payment'),
                    Discount::KIND_AMOUNT => t('label.discount_amount', [], 'payment'),
                ]),
            // Cents or percent according to the kind above, which is the whole reason the two share one column
            IntegerField::new('value')
                ->setLabel(t('label.discount_value', [], 'payment'))
                ->setHelp(t('help.discount_value', [], 'payment')),
            TextField::new('currency')
                ->setLabel(t('label.currency', [], 'payment'))
                ->setHelp(t('help.discount_currency', [], 'payment'))
                ->hideOnIndex(),
            DateTimeField::new('validFrom')
                ->setLabel(t('label.valid_from', [], 'payment'))
                ->setRequired(false),
            DateTimeField::new('validUntil')
                ->setLabel(t('label.valid_until', [], 'payment'))
                ->setRequired(false),
            MoneyField::new('minimumTotal')
                ->setLabel(t('label.minimum_total', [], 'payment'))
                ->setHelp(t('help.discount_minimum', [], 'payment'))
                ->setCurrency($this->currency())
                ->setStoredAsCents(),
            IntegerField::new('maxUses')
                ->setLabel(t('label.max_uses', [], 'payment'))
                ->setHelp(t('help.discount_max_uses', [], 'payment')),
            // Raised by the settled orders alone (see BasketCodeService::redeem()), so an admin never has to keep it
            IntegerField::new('usedCount')
                ->setLabel(t('label.used_count', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            BooleanField::new('active')
                ->setLabel(t('label.active', [], 'payment')),
            BooleanField::new('testMode')
                ->setLabel(t('label.test_mode', [], 'payment'))
                ->setHelp(t('help.code_test_mode', [], 'payment'))
                ->renderAsSwitch(false)
                ->hideOnForm(),
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'payment'))
                ->hideOnForm(),
        ];
    }

    // The shop's own currency, which is what the minimum of this screen is read in
    private function currency(): string
    {
        $currency = strtoupper(trim((string) $this->configService->get('shop-currency')));

        return '' === $currency ? 'EUR' : $currency;
    }
}
