<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller\Management;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class PaymentShortcutController extends AbstractController
{
    // EasyAdmin prefixes these with the Dashboard's own route name, giving management_payment_test_mode_toggle
    public const string TOGGLE_ROUTE_TEST_MODE = 'management_payment_test_mode_toggle';
    public const string TOGGLE_ROUTE_EMAIL_ATTACHMENTS = 'management_payment_email_attachments_toggle';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EntityManagerInterface $manager,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Flips the 'payment-test-mode' config value; which keys it then charges with is StripeGateway's own business, and the customer is told by the Basket:TestMode banner
    #[AdminRoute(
        path: '/payment/test-mode-toggle',
        name: 'payment_test_mode_toggle',
        options: ['methods' => ['POST']]
    )]
    public function toggleTestMode(Request $request): RedirectResponse
    {
        return $this->toggle($request, 'payment-test-mode', self::TOGGLE_ROUTE_TEST_MODE, 'flash.payment_test_mode');
    }

    // Flips the 'payment-email-attachments' config value; which documents each e-mail then carries stays ticked template by template in the e-mail builder, and BasketEmailFactory reads this switch before asking for any of them
    #[AdminRoute(
        path: '/payment/email-attachments-toggle',
        name: 'payment_email_attachments_toggle',
        options: ['methods' => ['POST']]
    )]
    public function toggleEmailAttachments(Request $request): RedirectResponse
    {
        return $this->toggle($request, 'payment-email-attachments', self::TOGGLE_ROUTE_EMAIL_ATTACHMENTS, 'flash.payment_email_attachments');
    }

    /**
     * Flips one boolean config from its tile, and says so.
     *
     * Shared by every toggle tile this bundle offers: the route name is the CSRF token's id, and the flash key is
     * suffixed with "_enabled" or "_disabled" - so a new tile is a route and two translation keys, nothing else.
     * A slug no site holds is left alone rather than created: the declaration is seeded by ConfigBundle, and a row
     * missing there means the bundle is half-installed.
     */
    private function toggle(Request $request, string $slug, string $route, string $flashKey): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $config = $this->configRepository->findOneBySlug($slug);
        if (null !== $config && $this->isCsrfTokenValid($route, $request->request->get('_token'))) {
            $enabled = !$this->configService->getBool($config->getValue());
            $config->setValue($enabled);
            $config->setModification(new \DateTime());

            $this->manager->flush();
            $this->configService->invalidateCache();

            $this->addFlash('success', $this->translator->trans(
                $flashKey . ($enabled ? '_enabled' : '_disabled'),
                [],
                'payment',
            ));
        }

        return $this->redirectToRoute('management');
    }
}
