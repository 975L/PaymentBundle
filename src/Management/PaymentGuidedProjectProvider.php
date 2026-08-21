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
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// This bundle's guided projects, continuing the order sequence after ConfigBundle (10-40), SiteBundle (50-80), UiBundle (90-110), SocialBundle (120-137), GalleryBundle (140-160) and BookBundle (170-190). Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
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

    // The role every payment management screen sits behind, the same ConfigBundle entry its controllers read (see PaymentCrudController, BasketCrudController) - a parcours walking screens the user can't open reads as a broken one
    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-admin');
    }
}
