<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Controller\Management\DiscountCrudController;
use c975L\PaymentBundle\Controller\Management\GiftCardCrudController;
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use c975L\PaymentBundle\Controller\Management\ShippingZoneCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// This bundle's guided projects, running the 7000 block GuidedProjectProviderInterface reserves them - the same docblock stating every other bundle's, so a range is read there rather than recopied here. Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class PaymentGuidedProjectProvider implements GuidedProjectProviderInterface
{
    // The configuration group the shop's settings are declared in (see configs.json), not a translation domain
    private const string SHOP_GROUP = 'shop';

    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getGuidedProjects(): array
    {
        return [
            $this->gatewaySetupProject(),
            $this->shopIdentityProject(),
            $this->testModeProject(),
            $this->transactionReviewProject(),
            $this->paymentLinkProject(),
            $this->giftCardIssueProject(),
            $this->discountCodeProject(),
            $this->shippingGridProject(),
            $this->shippingProject(),
            $this->archivedInvoiceProject(),
            $this->basketIntegrityProject(),
            $this->exportProject(),
        ];
    }

    // The very first gesture of a shop: nothing is charged until a provider's keys are stored, and they live among the sensitive entries of ConfigBundle's own screen rather than on any screen of this bundle
    private function gatewaySetupProject(): array
    {
        return [
            'slug' => 'payment-gateway-setup',
            'label' => 'label.guided_project_payment_gateway_setup',
            'description' => 'description.guided_project_payment_gateway_setup',
            'translation_domain' => 'payment',
            // Ahead of the test mode, which rehearses against keys this parcours is the one storing
            'order' => 7005,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_gateway_setup_open',
                    'description' => 'description.guided_step_payment_gateway_setup_open',
                    'narration' => 'narration.guided_step_payment_gateway_setup_open',
                    // The keys are sensitive entries, which ConfigCrudController lists apart from the rest of their group and only once asked to (see its showSensitive toggle)
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(ConfigCrudController::class)
                        ->setAction(Action::INDEX)
                        ->set('group', 'payment')
                        ->set('showSensitive', 1)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_gateway_setup_key',
                    'description' => 'description.guided_step_payment_gateway_setup_key',
                    'narration' => 'narration.guided_step_payment_gateway_setup_key',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_payment_gateway_setup_value',
                    'description' => 'description.guided_step_payment_gateway_setup_value',
                    'narration' => 'narration.guided_step_payment_gateway_setup_value',
                    'highlight' => '[data-guided-config-value]',
                ],
                [
                    'label' => 'label.guided_step_payment_gateway_setup_webhook',
                    'description' => 'description.guided_step_payment_gateway_setup_webhook',
                    'narration' => 'narration.guided_step_payment_gateway_setup_webhook',
                ],
                [
                    'label' => 'label.guided_step_payment_gateway_setup_default',
                    'description' => 'description.guided_step_payment_gateway_setup_default',
                    'narration' => 'narration.guided_step_payment_gateway_setup_default',
                ],
                [
                    // The health check screen is another one entirely, so the parcours names it rather than walking to it - see GatewayHealthCheckProvider
                    'label' => 'label.guided_step_payment_gateway_setup_check',
                    'description' => 'description.guided_step_payment_gateway_setup_check',
                    'narration' => 'narration.guided_step_payment_gateway_setup_check',
                ],
            ],
        ];
    }

    // What the shop calls itself, charges in and prints on its invoices, written before the first order rather than read off an invoice already sent
    private function shopIdentityProject(): array
    {
        return [
            'slug' => 'payment-shop-identity',
            'label' => 'label.guided_project_payment_shop_identity',
            'description' => 'description.guided_project_payment_shop_identity',
            'translation_domain' => 'payment',
            // Next to the keys, ahead of the test mode, the rehearsal order being written in this currency and numbered with this prefix
            'order' => 7007,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_shop_identity_open',
                    'description' => 'description.guided_step_payment_shop_identity_open',
                    'narration' => 'narration.guided_step_payment_shop_identity_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(ConfigCrudController::class)
                        ->setAction(Action::INDEX)
                        ->set('group', self::SHOP_GROUP)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_shop_identity_key',
                    'description' => 'description.guided_step_payment_shop_identity_key',
                    'narration' => 'narration.guided_step_payment_shop_identity_key',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_payment_shop_identity_value',
                    'description' => 'description.guided_step_payment_shop_identity_value',
                    'narration' => 'narration.guided_step_payment_shop_identity_value',
                    'highlight' => '[data-guided-config-value]',
                ],
                [
                    'label' => 'label.guided_step_payment_shop_identity_invoice',
                    'description' => 'description.guided_step_payment_shop_identity_invoice',
                    'narration' => 'narration.guided_step_payment_shop_identity_invoice',
                ],
            ],
        ];
    }

    // Rehearsed against the test keys before a real customer ever reaches the checkout
    private function testModeProject(): array
    {
        return [
            'slug' => 'payment-test-mode',
            'label' => 'label.guided_project_payment_test_mode',
            'description' => 'description.guided_project_payment_test_mode',
            'translation_domain' => 'payment',
            'order' => 7010,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_test_mode_open',
                    'description' => 'description.guided_step_payment_test_mode_open',
                    'narration' => 'narration.guided_step_payment_test_mode_open',
                    'url' => $this->urlGenerator->generate('management'),
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_enable',
                    'description' => 'description.guided_step_payment_test_mode_enable',
                    'narration' => 'narration.guided_step_payment_test_mode_enable',
                    'highlight' => 'form[action$="/payment/test-mode-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_check',
                    'description' => 'description.guided_step_payment_test_mode_check',
                    'narration' => 'narration.guided_step_payment_test_mode_check',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_disable',
                    'description' => 'description.guided_step_payment_test_mode_disable',
                    'narration' => 'narration.guided_step_payment_test_mode_disable',
                    'highlight' => 'form[action$="/payment/test-mode-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_done',
                    'description' => 'description.guided_step_payment_test_mode_done',
                    'narration' => 'narration.guided_step_payment_test_mode_done',
                ],
            ],
        ];
    }

    // A payment is read-only from the back office - reconciling it means finding it here, then following it to the provider that actually charged it
    private function transactionReviewProject(): array
    {
        return [
            'slug' => 'payment-transaction-review',
            'label' => 'label.guided_project_payment_transaction_review',
            'description' => 'description.guided_project_payment_transaction_review',
            'translation_domain' => 'payment',
            'order' => 7020,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_transaction_review_open',
                    'description' => 'description.guided_step_payment_transaction_review_open',
                    'narration' => 'narration.guided_step_payment_transaction_review_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(PaymentCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_detail',
                    'description' => 'description.guided_step_payment_transaction_review_detail',
                    'narration' => 'narration.guided_step_payment_transaction_review_detail',
                    'highlight' => '.action-detail',
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_provider',
                    'description' => 'description.guided_step_payment_transaction_review_provider',
                    'narration' => 'narration.guided_step_payment_transaction_review_provider',
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_basket',
                    'description' => 'description.guided_step_payment_transaction_review_basket',
                    'narration' => 'narration.guided_step_payment_transaction_review_basket',
                ],
            ],
        ];
    }

    // Being paid for something the catalogue does not sell, which is the one order an admin writes themselves rather than reads
    private function paymentLinkProject(): array
    {
        return [
            'slug' => 'payment-payment-link',
            'label' => 'label.guided_project_payment_payment_link',
            'description' => 'description.guided_project_payment_payment_link',
            'translation_domain' => 'payment',
            'order' => 7030,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_payment_link_open',
                    'description' => 'description.guided_step_payment_payment_link_open',
                    'narration' => 'narration.guided_step_payment_payment_link_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_start',
                    'description' => 'description.guided_step_payment_payment_link_start',
                    'narration' => 'narration.guided_step_payment_payment_link_start',
                    'highlight' => '.action-paymentLink',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_label',
                    'description' => 'description.guided_step_payment_payment_link_label',
                    'narration' => 'narration.guided_step_payment_payment_link_label',
                    'highlight' => '#form_label',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_amount',
                    'description' => 'description.guided_step_payment_payment_link_amount',
                    'narration' => 'narration.guided_step_payment_payment_link_amount',
                    'highlight' => '#form_amount',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_email',
                    'description' => 'description.guided_step_payment_payment_link_email',
                    'narration' => 'narration.guided_step_payment_payment_link_email',
                    'highlight' => '#form_email',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_description',
                    'description' => 'description.guided_step_payment_payment_link_description',
                    'narration' => 'narration.guided_step_payment_payment_link_description',
                    'highlight' => '#form_description',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_create',
                    'description' => 'description.guided_step_payment_payment_link_create',
                    'narration' => 'narration.guided_step_payment_payment_link_create',
                    'highlight' => '#form_create',
                ],
            ],
        ];
    }

    // Minting a card outside any sale, its code shown in the flash it lands back on and kept in the card listing, then switching one off once it is reported lost or stolen
    private function giftCardIssueProject(): array
    {
        return [
            'slug' => 'payment-gift-card-issue',
            'label' => 'label.guided_project_payment_gift_card_issue',
            'description' => 'description.guided_project_payment_gift_card_issue',
            'translation_domain' => 'payment',
            'order' => 7040,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_open',
                    'description' => 'description.guided_step_payment_gift_card_issue_open',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(GiftCardCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_start',
                    'description' => 'description.guided_step_payment_gift_card_issue_start',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_start',
                    'highlight' => '.action-issue',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_amount',
                    'description' => 'description.guided_step_payment_gift_card_issue_amount',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_amount',
                    'highlight' => '#form_amount',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_validity',
                    'description' => 'description.guided_step_payment_gift_card_issue_validity',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_validity',
                    'highlight' => '#form_validUntil',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_confirm',
                    'description' => 'description.guided_step_payment_gift_card_issue_confirm',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_confirm',
                    'highlight' => '#form_issue',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_code',
                    'description' => 'description.guided_step_payment_gift_card_issue_code',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_code',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_edit',
                    'description' => 'description.guided_step_payment_gift_card_issue_edit',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_deactivate',
                    'description' => 'description.guided_step_payment_gift_card_issue_deactivate',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_deactivate',
                    'highlight' => '#GiftCard_active',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_save',
                    'description' => 'description.guided_step_payment_gift_card_issue_save',
                    'narration' => 'narration.guided_step_payment_gift_card_issue_save',
                    'highlight' => '.action-saveAndReturn',
                ],
            ],
        ];
    }

    // Writing a promotional code, whose two fields decide each other: what "value" holds is read by the kind chosen above it
    private function discountCodeProject(): array
    {
        return [
            'slug' => 'payment-discount-code',
            'label' => 'label.guided_project_payment_discount_code',
            'description' => 'description.guided_project_payment_discount_code',
            'translation_domain' => 'payment',
            'order' => 7050,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_discount_code_open',
                    'description' => 'description.guided_step_payment_discount_code_open',
                    'narration' => 'narration.guided_step_payment_discount_code_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(DiscountCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_new',
                    'description' => 'description.guided_step_payment_discount_code_new',
                    'narration' => 'narration.guided_step_payment_discount_code_new',
                    'highlight' => '.action-new',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_code',
                    'description' => 'description.guided_step_payment_discount_code_code',
                    'narration' => 'narration.guided_step_payment_discount_code_code',
                    'highlight' => '#Discount_code',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_kind',
                    'description' => 'description.guided_step_payment_discount_code_kind',
                    'narration' => 'narration.guided_step_payment_discount_code_kind',
                    'highlight' => '#Discount_kind',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_value',
                    'description' => 'description.guided_step_payment_discount_code_value',
                    'narration' => 'narration.guided_step_payment_discount_code_value',
                    'highlight' => '#Discount_value',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_limits',
                    'description' => 'description.guided_step_payment_discount_code_limits',
                    'narration' => 'narration.guided_step_payment_discount_code_limits',
                    'highlight' => '#Discount_maxUses',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_live',
                    'description' => 'description.guided_step_payment_discount_code_live',
                    'narration' => 'narration.guided_step_payment_discount_code_live',
                ],
            ],
        ];
    }

    // What the shop charges to post a parcel, written once before the first order rather than discovered on a month of them - and the one screen whose emptiness costs money in silence, an unwritten grid posting everything free
    private function shippingGridProject(): array
    {
        return [
            'slug' => 'payment-shipping-grid',
            'label' => 'label.guided_project_payment_shipping_grid',
            'description' => 'description.guided_project_payment_shipping_grid',
            'translation_domain' => 'payment',
            // Just before the parcel round it makes possible: nothing can be posted at a price the grid does not state
            'order' => 7055,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_shipping_grid_open',
                    'description' => 'description.guided_step_payment_shipping_grid_open',
                    'narration' => 'narration.guided_step_payment_shipping_grid_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(ShippingZoneCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_grid_new',
                    'description' => 'description.guided_step_payment_shipping_grid_new',
                    'narration' => 'narration.guided_step_payment_shipping_grid_new',
                    'highlight' => '.action-new',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_grid_name',
                    'description' => 'description.guided_step_payment_shipping_grid_name',
                    'narration' => 'narration.guided_step_payment_shipping_grid_name',
                    'highlight' => '#ShippingZone_name',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_grid_countries',
                    'description' => 'description.guided_step_payment_shipping_grid_countries',
                    'narration' => 'narration.guided_step_payment_shipping_grid_countries',
                    'highlight' => '#ShippingZone_countries',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_grid_rates',
                    'description' => 'description.guided_step_payment_shipping_grid_rates',
                    'narration' => 'narration.guided_step_payment_shipping_grid_rates',
                    'highlight' => '[data-shipping-rates]',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_grid_active',
                    'description' => 'description.guided_step_payment_shipping_grid_active',
                    'narration' => 'narration.guided_step_payment_shipping_grid_active',
                    'highlight' => '#ShippingZone_active',
                ],
                [
                    // The health check screen is another one entirely and only the opening step may carry an url, so the parcours names it rather than walking to it - see ShippingHealthCheckProvider for what it reports
                    'label' => 'label.guided_step_payment_shipping_grid_check',
                    'description' => 'description.guided_step_payment_shipping_grid_check',
                    'narration' => 'narration.guided_step_payment_shipping_grid_check',
                ],
            ],
        ];
    }

    // The parcels of the day, from the orders that owe one to the email telling the customer they are on their way
    private function shippingProject(): array
    {
        return [
            'slug' => 'payment-shipping',
            'label' => 'label.guided_project_payment_shipping',
            'description' => 'description.guided_project_payment_shipping',
            'translation_domain' => 'payment',
            'order' => 7060,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_shipping_open',
                    'description' => 'description.guided_step_payment_shipping_open',
                    'narration' => 'narration.guided_step_payment_shipping_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_filter',
                    'description' => 'description.guided_step_payment_shipping_filter',
                    'narration' => 'narration.guided_step_payment_shipping_filter',
                    'highlight' => '.action-filterPaid',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_labels',
                    'description' => 'description.guided_step_payment_shipping_labels',
                    'narration' => 'narration.guided_step_payment_shipping_labels',
                    'highlight' => '.action-shippingLabels',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_send',
                    'description' => 'description.guided_step_payment_shipping_send',
                    'narration' => 'narration.guided_step_payment_shipping_send',
                    'highlight' => '.action-sendPhysicalItems',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_done',
                    'description' => 'description.guided_step_payment_shipping_done',
                    'narration' => 'narration.guided_step_payment_shipping_done',
                ],
            ],
        ];
    }

    // An order two years old has left the list without leaving the shop: kept for its ten years, it is asked for again the day a customer needs their invoice back
    private function archivedInvoiceProject(): array
    {
        return [
            'slug' => 'payment-archived-invoice',
            'label' => 'label.guided_project_payment_archived_invoice',
            'description' => 'description.guided_project_payment_archived_invoice',
            'translation_domain' => 'payment',
            // After the parcel round: an order is archived once paid or shipped, which is where the life of an order ends on the back office
            'order' => 7065,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_archived_invoice_open',
                    'description' => 'description.guided_step_payment_archived_invoice_open',
                    'narration' => 'narration.guided_step_payment_archived_invoice_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_archived_invoice_filter',
                    'description' => 'description.guided_step_payment_archived_invoice_filter',
                    'narration' => 'narration.guided_step_payment_archived_invoice_filter',
                    'highlight' => '.action-filterArchived',
                ],
                [
                    'label' => 'label.guided_step_payment_archived_invoice_search',
                    'description' => 'description.guided_step_payment_archived_invoice_search',
                    'narration' => 'narration.guided_step_payment_archived_invoice_search',
                    'highlight' => '.form-action-search',
                ],
                [
                    'label' => 'label.guided_step_payment_archived_invoice_invoice',
                    'description' => 'description.guided_step_payment_archived_invoice_invoice',
                    'narration' => 'narration.guided_step_payment_archived_invoice_invoice',
                    'highlight' => '.action-invoice',
                ],
            ],
        ];
    }

    // The six weekly checks are run by a scheduler nobody watches - what is missing is the habit of reading what they found, and of following each count to the orders behind it
    private function basketIntegrityProject(): array
    {
        return [
            'slug' => 'payment-basket-integrity',
            'label' => 'label.guided_project_payment_basket_integrity',
            'description' => 'description.guided_project_payment_basket_integrity',
            'translation_domain' => 'payment',
            'order' => 7070,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_basket_integrity_open',
                    'description' => 'description.guided_step_payment_basket_integrity_open',
                    'narration' => 'narration.guided_step_payment_basket_integrity_open',
                    // ConfigBundle's own screen, this bundle only filling six of its rows: the checks are the shop's, the page they land on is the site's
                    'url' => $this->urlGenerator->generate('management_health_check_index'),
                ],
                [
                    'label' => 'label.guided_step_payment_basket_integrity_run',
                    'description' => 'description.guided_step_payment_basket_integrity_run',
                    'narration' => 'narration.guided_step_payment_basket_integrity_run',
                    'highlight' => 'form[action$="/health-check/run"] button',
                ],
                [
                    // The rows carry their kind as a data attribute for the table's own filtering (see ConfigBundle's health-check-table controller), which is what lets a parcours point at this bundle's six among everything else the page lists
                    'label' => 'label.guided_step_payment_basket_integrity_rows',
                    'description' => 'description.guided_step_payment_basket_integrity_rows',
                    'narration' => 'narration.guided_step_payment_basket_integrity_rows',
                    'highlight' => 'tr[data-kind="' . BasketIntegrityHealthCheckProvider::KIND . '"]',
                ],
                [
                    'label' => 'label.guided_step_payment_basket_integrity_offenders',
                    'description' => 'description.guided_step_payment_basket_integrity_offenders',
                    'narration' => 'narration.guided_step_payment_basket_integrity_offenders',
                    'highlight' => '.health-check-advice-items',
                ],
                [
                    'label' => 'label.guided_step_payment_basket_integrity_acknowledge',
                    'description' => 'description.guided_step_payment_basket_integrity_acknowledge',
                    'narration' => 'narration.guided_step_payment_basket_integrity_acknowledge',
                    'highlight' => '[data-action="health-check-table#acknowledge"]',
                ],
                [
                    'label' => 'label.guided_step_payment_basket_integrity_done',
                    'description' => 'description.guided_step_payment_basket_integrity_done',
                    'narration' => 'narration.guided_step_payment_basket_integrity_done',
                ],
            ],
        ];
    }

    // The orders leaving the site as a flat table, for the accountant or another tool - never as an archive to re-import (see BasketCrudController's export group)
    private function exportProject(): array
    {
        return [
            'slug' => 'payment-export',
            'label' => 'label.guided_project_payment_export',
            'description' => 'description.guided_project_payment_export',
            'translation_domain' => 'payment',
            'order' => 7080,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_export_open',
                    'description' => 'description.guided_step_payment_export_open',
                    'narration' => 'narration.guided_step_payment_export_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    // EasyAdmin renders a group as a dropdown carrying its name as a data attribute, not as an `action-<name>` class
                    'label' => 'label.guided_step_payment_export_format',
                    'description' => 'description.guided_step_payment_export_format',
                    'narration' => 'narration.guided_step_payment_export_format',
                    'highlight' => '[data-action-group-name="export"]',
                ],
                [
                    'label' => 'label.guided_step_payment_export_payments',
                    'description' => 'description.guided_step_payment_export_payments',
                    'narration' => 'narration.guided_step_payment_export_payments',
                ],
            ],
        ];
    }

    // The role every payment management screen sits behind, the same ConfigBundle entry its controllers read (see PaymentCrudController, BasketCrudController) - a parcours walking screens the user can't open reads as a broken one
    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-admin');
    }
}
