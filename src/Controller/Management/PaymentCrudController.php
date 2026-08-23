<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

class PaymentCrudController extends AbstractCrudController
{
    private const string TABLE = 'payment_payment';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Connection $connection,
        private readonly TableExporter $tableExporter,
        private readonly TranslatorInterface $translator,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('basket')
                ->setLabel(t('label.basket', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            BooleanField::new('isFinished')
                ->setLabel(t('label.is_finished', [], 'payment')),
            IntegerField::new('amount')
                ->setLabel(t('label.amount', [], 'payment')),
            TextField::new('currency')
                ->setLabel(t('label.currency', [], 'payment')),
            TextField::new('gateway')
                ->setLabel(t('label.gateway', [], 'payment')),
            TextField::new('transaction_id')
                ->setLabel(t('label.transaction_id', [], 'payment'))
                ->formatValue(function ($value, $payment) {
                    $url = $this->getTransactionUrl($payment);

                    return null === $url ? $value : sprintf('<a href="%s" target="_blank">%s</a>', $url, $value);
                }),
            TextField::new('payment_method')
                ->setLabel(t('label.payment_method', [], 'payment')),
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
            DateTimeField::new('modification')
                ->setLabel(t('label.modification', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail(),
        ];
    }

    // The provider's own page for a transaction, asked of the gateway that charged it - a payment recorded before this bundle named its provider carries no slug and falls back on the active one
    private function getTransactionUrl(Payment $payment): ?string
    {
        $transactionId = $payment->getTransactionId();
        if (null === $transactionId || '' === $transactionId) {
            return null;
        }

        $slug = $payment->getGateway();
        $gateway = null !== $slug && $this->gatewayRegistry->has($slug) ? $this->gatewayRegistry->get($slug) : $this->gatewayRegistry->getActive();

        return $gateway->getTransactionUrl($transactionId);
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

        $viewTransaction = Action::new('viewTransaction', t('label.transaction', [], 'payment'), 'fa fa-file-invoice')
            ->linkToUrl(fn (Payment $payment) => $this->getTransactionUrl($payment) ?? '#')
            ->displayIf(fn (Payment $payment) => null !== $this->getTransactionUrl($payment));

        // Same flat table dump as the baskets it pays for (see BasketCrudController), the provider's reference travelling with it: it is what a payment is reconciled by, and the admin already reads it on the index
        $exportGroup = ActionGroup::new('export', t('label.export', [], 'payment'), 'fa fa-download')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('exportSql', 'SQL')->linkToCrudAction('exportSql'))
            ->addAction(Action::new('exportCsv', 'CSV')->linkToCrudAction('exportCsv'))
            ->addAction(Action::new('exportJson', 'JSON')->linkToCrudAction('exportJson'))
        ;

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $viewTransaction)
            ->add(Crud::PAGE_INDEX, $exportGroup)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.detail', [], 'EasyAdminBundle'),
            ))
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::DELETE, $role)
            ->setPermission(Action::DETAIL, $role)
            ->setPermission('viewTransaction', $role)
            ->setPermission('exportSql', $role)
            ->setPermission('exportCsv', $role)
            ->setPermission('exportJson', $role)
        ;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->overrideTemplate('crud/index', '@c975LPayment/management/payment_crud_index.html.twig')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isFinished'))
        ;
    }

    #[AdminRoute]
    public function exportSql(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->tableExporter->export(ExportFormat::Sql, self::TABLE, $this->fetchExportRows());
    }

    #[AdminRoute]
    public function exportCsv(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->tableExporter->export(ExportFormat::Csv, self::TABLE, $this->fetchExportRows());
    }

    #[AdminRoute]
    public function exportJson(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->tableExporter->export(ExportFormat::Json, self::TABLE, $this->fetchExportRows());
    }

    private function fetchExportRows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM `' . self::TABLE . '` ORDER BY `id`');
    }
}
