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
use c975L\PaymentBundle\Entity\Basket;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

class BasketCrudController extends AbstractCrudController
{
    private const string TABLE = 'payment_basket';

    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TableExporter $tableExporter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Basket::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IntegerField::new('id')
                ->setFormTypeOption('disabled', 'disabled')
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('number')
                ->setLabel(t('label.order_number', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            AssociationField::new('payment')
                ->setLabel(t('label.payment', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('status')
                ->setLabel(t('label.status', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            ChoiceField::new('contentFlags')
                ->setLabel(t('label.content', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled')
                ->setChoices([
                    'label.digital' => Basket::CONTENT_FLAG_DIGITAL,
                    'label.physical' => Basket::CONTENT_FLAG_PHYSICAL,
                    'label.mixed' => Basket::FLAG_PRODUCT_MIXED,
                    'label.crowdfunding_digital' => Basket::CONTENT_FLAG_CF_DIGITAL,
                    'label.crowdfunding_shipping' => Basket::CONTENT_FLAG_CF_SHIPPING,
                    'label.crowdfunding_mixed' => Basket::FLAG_CF_MIXED,
                    'label.all_mixed' => Basket::FLAG_MIXED,
                ])
                ->formatValue(function ($value, $entity) {
                    if (Basket::FLAG_MIXED == $value) {
                        return 'Mixed (Digital + Physical + Crowdfunding)';
                    } elseif (Basket::FLAG_DIGITAL_ONLY == $value) {
                        return 'Digital Only (Products + Crowdfunding)';
                    } elseif (Basket::FLAG_NEEDS_SHIPPING == $value) {
                        return 'Requires Shipping (Physical + Crowdfunding)';
                    } elseif (Basket::CONTENT_FLAG_DIGITAL == $value) {
                        return 'Digital Product';
                    } elseif (Basket::CONTENT_FLAG_PHYSICAL == $value) {
                        return 'Physical Product';
                    } elseif (Basket::CONTENT_FLAG_CF_DIGITAL == $value) {
                        return 'Digital Crowdfunding';
                    } elseif (Basket::CONTENT_FLAG_CF_SHIPPING == $value) {
                        return 'Physical Crowdfunding';
                    }

                    return 'Unknown (' . $value . ')';
                }),
            IntegerField::new('total')
                ->setLabel(t('label.total', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            IntegerField::new('shipping')
                ->setLabel(t('label.shipping', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('currency')
                ->setLabel(t('label.currency', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            IntegerField::new('quantity')
                ->setLabel(t('label.quantity', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            EmailField::new('email')
                ->setLabel(t('label.email', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('name')
                ->setLabel(t('label.name', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('address')
                ->setLabel(t('label.address', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('zip')
                ->setLabel(t('label.zip', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('city')
                ->setLabel(t('label.city', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('country')
                ->setLabel(t('label.country', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled'),
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled')
                ->setFormTypeOption('disabled', 'disabled'),
            DateTimeField::new('modification')
                ->setLabel(t('label.modification', [], 'payment'))
                ->hideOnIndex()
                ->setFormTypeOption('disabled', 'disabled')
                ->onlyOnDetail()
                ->setFormTypeOption('disabled', 'disabled'),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

        // Paid baskets
        $filterPaid = Action::new('filterPaid', 'Paid', 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->set('filters[status][value]', 'paid')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // Validated baskets
        $filterValidated = Action::new('filterValidated', 'Validated', 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->set('filters[status][value]', 'validated')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // New baskets
        $filterNew = Action::new('filterNew', 'New', 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->set('filters[status][value]', 'new')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // Send items
        $sendPhysicalItems = Action::new('sendPhysicalItems', 'label.send_items')
            ->linkToRoute('items_shipped', fn (Basket $basket): array => [
                'number' => $basket->getNumber(),
                'type' => 'product',
            ])
            ->setHtmlAttributes([
                'target' => '_blank',
            ])
            ->displayIf(fn (Basket $basket): bool => 'paid' === $basket->getStatus()
                && null !== $basket->getNumber()
                && ($basket->getContentFlags() & Basket::CONTENT_FLAG_PHYSICAL)
                && null === $basket->getItemsShipped());

        // Send counterparts
        $sendCounterparts = Action::new('sendCounterparts', 'label.send_counterparts')
            ->linkToRoute('items_shipped', fn (Basket $basket): array => [
                'number' => $basket->getNumber(),
                'type' => 'crowdfunding',
            ])
            ->setHtmlAttributes([
                'target' => '_blank',
            ])
            ->displayIf(fn (Basket $basket): bool => 'paid' === $basket->getStatus()
                && null !== $basket->getNumber()
                && ($basket->getContentFlags() & Basket::CONTENT_FLAG_CF_SHIPPING)
                && null === $basket->getCounterpartsShipped());

        // Orders leave the site as a flat table dump (see ConfigBundle's TableExporter), never as a re-importable content archive: a basket belongs to the site that took it, and nothing syncs it from dev to prod
        $exportGroup = ActionGroup::new('export', t('label.export', [], 'payment'), 'fa fa-download')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('exportSql', 'SQL')->linkToCrudAction('exportSql'))
            ->addAction(Action::new('exportCsv', 'CSV')->linkToCrudAction('exportCsv'))
            ->addAction(Action::new('exportJson', 'JSON')->linkToCrudAction('exportJson'))
        ;

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $exportGroup)
            ->add(Crud::PAGE_INDEX, $filterPaid)
            ->add(Crud::PAGE_INDEX, $filterValidated)
            ->add(Crud::PAGE_INDEX, $filterNew)
            ->add(Crud::PAGE_INDEX, $sendPhysicalItems)
            ->add(Crud::PAGE_INDEX, $sendCounterparts)
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
            ->setPermission('filterPaid', $role)
            ->setPermission('filterValidated', $role)
            ->setPermission('filterNew', $role)
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
            ->setDefaultSort(['id' => 'DESC'])
            ->overrideTemplate('crud/index', '@c975LPayment/management/basket_crud_index.html.twig')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('number')
            ->add('status')
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

    // The security token is never exported, the same way UserCrudController never exports a hashed password: it is what guards the public order-tracking url (see BasketController), so a dump carrying it hands over every customer's order page
    // The column is read from the mapping rather than spelled out, its name depending on the naming strategy the site configures
    private function fetchExportRows(): array
    {
        $rows = $this->entityManager->getConnection()
            ->fetchAllAssociative('SELECT * FROM `' . self::TABLE . '` ORDER BY `id`');
        $securityTokenColumn = $this->entityManager->getClassMetadata(Basket::class)->getColumnName('securityToken');

        return array_map(static fn (array $row): array => array_diff_key($row, [$securityTokenColumn => null]), $rows);
    }
}
