<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Controller\Management\DiscountCrudController;
use c975L\PaymentBundle\Controller\Management\GiftCardCrudController;
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// This bundle's guided projects, continuing the order sequence after ConfigBundle (10-40), SiteBundle (50-80), UiBundle (90-110), SocialBundle (120-137), GalleryBundle (140-190) and BookBundle (170-190). Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class PaymentGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getGuidedProjects(): array
    {
        return [
            $this->testModeProject(),
            $this->transactionReviewProject(),
            $this->paymentLinkProject(),
            $this->giftCardIssueProject(),
            $this->discountCodeProject(),
            $this->shippingProject(),
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
            'order' => 200,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_test_mode_open',
                    'description' => 'description.guided_step_payment_test_mode_open',
                    'url' => $this->urlGenerator->generate('management'),
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_enable',
                    'description' => 'description.guided_step_payment_test_mode_enable',
                    'highlight' => 'form[action$="/payment/test-mode-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_check',
                    'description' => 'description.guided_step_payment_test_mode_check',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_disable',
                    'description' => 'description.guided_step_payment_test_mode_disable',
                    'highlight' => 'form[action$="/payment/test-mode-toggle"] button',
                ],
                [
                    'label' => 'label.guided_step_payment_test_mode_done',
                    'description' => 'description.guided_step_payment_test_mode_done',
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
            'order' => 210,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_transaction_review_open',
                    'description' => 'description.guided_step_payment_transaction_review_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(PaymentCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_detail',
                    'description' => 'description.guided_step_payment_transaction_review_detail',
                    'highlight' => '.action-detail',
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_provider',
                    'description' => 'description.guided_step_payment_transaction_review_provider',
                ],
                [
                    'label' => 'label.guided_step_payment_transaction_review_basket',
                    'description' => 'description.guided_step_payment_transaction_review_basket',
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
            'order' => 220,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_payment_link_open',
                    'description' => 'description.guided_step_payment_payment_link_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_start',
                    'description' => 'description.guided_step_payment_payment_link_start',
                    'highlight' => '.action-paymentLink',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_label',
                    'description' => 'description.guided_step_payment_payment_link_label',
                    'highlight' => '#form_label',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_amount',
                    'description' => 'description.guided_step_payment_payment_link_amount',
                    'highlight' => '#form_amount',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_email',
                    'description' => 'description.guided_step_payment_payment_link_email',
                    'highlight' => '#form_email',
                ],
                [
                    'label' => 'label.guided_step_payment_payment_link_create',
                    'description' => 'description.guided_step_payment_payment_link_create',
                    'highlight' => '#form_create',
                ],
            ],
        ];
    }

    // Minting a card outside any sale, which is the one moment its code is ever shown - no screen prints it again afterwards
    private function giftCardIssueProject(): array
    {
        return [
            'slug' => 'payment-gift-card-issue',
            'label' => 'label.guided_project_payment_gift_card_issue',
            'description' => 'description.guided_project_payment_gift_card_issue',
            'translation_domain' => 'payment',
            'order' => 230,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_open',
                    'description' => 'description.guided_step_payment_gift_card_issue_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(GiftCardCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_start',
                    'description' => 'description.guided_step_payment_gift_card_issue_start',
                    'highlight' => '.action-issue',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_amount',
                    'description' => 'description.guided_step_payment_gift_card_issue_amount',
                    'highlight' => '#form_amount',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_validity',
                    'description' => 'description.guided_step_payment_gift_card_issue_validity',
                    'highlight' => '#form_validUntil',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_confirm',
                    'description' => 'description.guided_step_payment_gift_card_issue_confirm',
                    'highlight' => '#form_issue',
                ],
                [
                    'label' => 'label.guided_step_payment_gift_card_issue_code',
                    'description' => 'description.guided_step_payment_gift_card_issue_code',
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
            'order' => 240,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_discount_code_open',
                    'description' => 'description.guided_step_payment_discount_code_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(DiscountCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_new',
                    'description' => 'description.guided_step_payment_discount_code_new',
                    'highlight' => '.action-new',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_code',
                    'description' => 'description.guided_step_payment_discount_code_code',
                    'highlight' => '#Discount_code',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_kind',
                    'description' => 'description.guided_step_payment_discount_code_kind',
                    'highlight' => '#Discount_kind',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_value',
                    'description' => 'description.guided_step_payment_discount_code_value',
                    'highlight' => '#Discount_value',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_limits',
                    'description' => 'description.guided_step_payment_discount_code_limits',
                    'highlight' => '#Discount_maxUses',
                ],
                [
                    'label' => 'label.guided_step_payment_discount_code_live',
                    'description' => 'description.guided_step_payment_discount_code_live',
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
            'order' => 250,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_payment_shipping_open',
                    'description' => 'description.guided_step_payment_shipping_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(BasketCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl(),
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_filter',
                    'description' => 'description.guided_step_payment_shipping_filter',
                    'highlight' => '.action-filterPaid',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_labels',
                    'description' => 'description.guided_step_payment_shipping_labels',
                    'highlight' => '.action-shippingLabels',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_send',
                    'description' => 'description.guided_step_payment_shipping_send',
                    'highlight' => '.action-sendPhysicalItems',
                ],
                [
                    'label' => 'label.guided_step_payment_shipping_done',
                    'description' => 'description.guided_step_payment_shipping_done',
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
