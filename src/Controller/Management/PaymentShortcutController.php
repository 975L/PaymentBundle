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
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_payment_test_mode_toggle
    public const string TOGGLE_ROUTE_TEST_MODE = 'management_payment_test_mode_toggle';

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
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $config = $this->configRepository->findOneBySlug('payment-test-mode');
        if (null !== $config && $this->isCsrfTokenValid(self::TOGGLE_ROUTE_TEST_MODE, $request->request->get('_token'))) {
            $enabled = !$this->configService->getBool($config->getValue());
            $config->setValue($enabled);
            $config->setModification(new \DateTime());

            $this->manager->flush();
            $this->configService->invalidateCache();

            $this->addFlash('success', $this->translator->trans(
                $enabled ? 'flash.payment_test_mode_enabled' : 'flash.payment_test_mode_disabled',
                [],
                'payment',
            ));
        }

        return $this->redirectToRoute('management');
    }
}
