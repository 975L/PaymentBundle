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
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly BasketRepository $basketRepository,
        private readonly PdfGeneratorInterface $pdfGenerator,
        private readonly BasketServiceInterface $basketService,
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
            DateTimeField::new('archived')
                ->setLabel(t('label.archived', [], 'payment'))
                ->onlyOnDetail()
                ->setFormTypeOption('disabled', 'disabled'),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

        // Paid baskets
        $filterPaid = Action::new('filterPaid', t('label.filter_paid', [], 'payment'), 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('archived')
                ->set('filters[status][value]', 'paid')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // Validated baskets
        $filterValidated = Action::new('filterValidated', t('label.filter_validated', [], 'payment'), 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('archived')
                ->set('filters[status][value]', 'validated')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // New baskets
        $filterNew = Action::new('filterNew', t('label.filter_new', [], 'payment'), 'fa fa-filter')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('archived')
                ->set('filters[status][value]', 'new')
                ->set('filters[status][comparison]', '=')
                ->generateUrl())
        ;

        // Archived orders, the only view that shows them
        $filterArchived = Action::new('filterArchived', t('label.filter_archived', [], 'payment'), 'fa fa-box-archive')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->set('archived', 1)
                ->generateUrl())
        ;

        // Send items
        $sendPhysicalItems = Action::new('sendPhysicalItems', t('label.send_items', [], 'payment'))
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
        $sendCounterparts = Action::new('sendCounterparts', t('label.send_counterparts', [], 'payment'))
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

        // The parcels of the day, printed in one go. A global action and not a per-row one: what a shopkeeper does is print the sheet, stick the labels and post the lot, not fetch one label at a time
        $shippingLabels = Action::new('shippingLabels', t('label.shipping_labels', [], 'payment'), 'fa fa-tags')
            ->createAsGlobalAction()
            ->linkToCrudAction('shippingLabels')
            ->setHtmlAttributes(['target' => '_blank']);

        // The order's own invoice, the same file the customer downloads - a shop is asked for it often enough that hunting for the customer's page is not the answer
        $invoice = Action::new('invoice', t('label.invoice', [], 'payment'), 'fa fa-file-invoice')
            ->linkToRoute('basket_invoice_pdf', fn (Basket $basket): array => [
                'number' => $basket->getNumber(),
                'securityToken' => $basket->getSecurityToken(),
            ])
            ->setHtmlAttributes(['target' => '_blank'])
            ->displayIf(static fn (Basket $basket): bool => null !== $basket->getInvoiceNumber());

        // Being paid for something the catalogue does not sell - a deposit, a repair, an invoice. A global action: there is no row to start from, the order is written by the form it opens
        $paymentLink = Action::new('paymentLink', t('label.payment_link', [], 'payment'), 'fa fa-link')
            ->createAsGlobalAction()
            ->linkToCrudAction('paymentLink');

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
            ->add(Crud::PAGE_INDEX, $paymentLink)
            ->add(Crud::PAGE_INDEX, $shippingLabels)
            ->add(Crud::PAGE_INDEX, $invoice)
            ->add(Crud::PAGE_INDEX, $filterPaid)
            ->add(Crud::PAGE_INDEX, $filterValidated)
            ->add(Crud::PAGE_INDEX, $filterNew)
            ->add(Crud::PAGE_INDEX, $filterArchived)
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
            ->setPermission('filterArchived', $role)
            ->setPermission('paymentLink', $role)
            ->setPermission('shippingLabels', $role)
            ->setPermission('invoice', $role)
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

    /**
     * Leaves the archived orders out of the list, unless the archive is what is being asked for.
     *
     * An order kept for the ten years of the accounting obligation has stopped being business the shop is
     * handling, and the CNIL asks for it to be set apart rather than left among the current ones. A condition
     * on the list and an action of its own are the logical separation it accepts - no second table needed.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $queryBuilder->getRootAliases()[0];
        $archived = $searchDto->getRequest()->query->getBoolean('archived');

        return $queryBuilder->andWhere($archived ? $alias . '.archived IS NOT NULL' : $alias . '.archived IS NULL');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('number')
            ->add('status')
        ;
    }

    /**
     * Writes an order for something the catalogue does not sell, and shows the address to send whoever settles it.
     *
     * The same BasketService::createPaymentLink() nothing else calls: the order is frozen exactly as a shared one
     * is, so the checkout, the webhook, the payment row and this very list all read it as the order it is. What
     * tells the two apart afterwards is the kind of its line.
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function paymentLink(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $currency = strtoupper(trim((string) $this->configService->get('shop-currency')));

        $form = $this->createFormBuilder()
            ->add('label', TextType::class, [
                'label' => t('label.payment_link_label', [], 'payment'),
                'help' => t('help.payment_link_label', [], 'payment'),
            ])
            ->add('amount', MoneyType::class, [
                'label' => t('label.payment_link_amount', [], 'payment'),
                'help' => t('help.payment_link_amount', [], 'payment'),
                // Typed in the currency the customer reads, stored in cents like every other amount here
                'divisor' => 100,
                'currency' => $currency,
            ])
            ->add('email', EmailType::class, [
                'label' => t('label.email', [], 'payment'),
                'help' => t('help.payment_link_email', [], 'payment'),
            ])
            ->add('description', TextareaType::class, [
                'label' => t('label.description', [], 'payment'),
                'required' => false,
            ])
            // Part of the form rather than written in the template: EasyAdmin's own form theme is what gives it the dashboard's button
            ->add('create', SubmitType::class, [
                'label' => t('label.payment_link_create', [], 'payment'),
            ])
            ->getForm()
        ;
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $url = $this->basketService->createPaymentLink(
                (string) $data['label'],
                (int) round((float) $data['amount']),
                (string) $data['email'],
                $data['description'],
            );

            $this->addFlash('success', $this->translator->trans('flash.payment_link_created', [], 'payment'));
            // Carried in a flash of its own and answered with a redirection: the admin reads the address on the page they came from, and a refresh writes no second order
            $this->addFlash('payment_link_url', $url);

            return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction('paymentLink')->generateUrl());
        }

        // The address is what the admin came for, and it is long enough to be copied rather than read
        return $this->render('@c975LPayment/management/payment_link.html.twig', [
            'form' => $form->createView(),
        ]);
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

    /**
     * The address labels of everything still to post, as one sheet to feed the printer.
     *
     * Plain address labels and nothing else: a carrier's own label carries a tracking barcode that only that
     * carrier can issue, which is an account and an API and not a template.
     */
    #[AdminRoute]
    public function shippingLabels(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        // An order paid for online and shipped nowhere has no address at all, and a blank label wastes one of the ten
        $baskets = array_values(array_filter(
            $this->basketRepository->findAwaitingShipping(),
            static fn (Basket $basket): bool => null !== $basket->getAddress() && '' !== $basket->getAddress()
        ));

        if ([] === $baskets) {
            $this->addFlash('info', $this->translator->trans('flash.no_shipping_labels', [], 'payment'));

            return $this->redirect($this->adminUrlGenerator->setAction(Action::INDEX)->generateUrl());
        }

        $pdf = $this->pdfGenerator->render('@c975LPayment/management/shipping_labels.html.twig', [
            'baskets' => $baskets,
            'sender' => (string) $this->configService->get('shop-name'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquettes.pdf"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    // The tokens are never exported, a dump carrying them handing over every customer's order page and every open basket; the columns are read from the mapping, their names depending on the site's naming strategy
    private function fetchExportRows(): array
    {
        $rows = $this->entityManager->getConnection()
            ->fetchAllAssociative('SELECT * FROM `' . self::TABLE . '` ORDER BY `id`');
        $metadata = $this->entityManager->getClassMetadata(Basket::class);
        $secrets = [];
        foreach (['securityToken', 'shareToken', 'recoveryToken'] as $field) {
            $secrets[$metadata->getColumnName($field)] = null;
        }

        return array_map(static fn (array $row): array => array_diff_key($row, $secrets), $rows);
    }
}
