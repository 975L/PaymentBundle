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
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Says on the dashboard what the visitor used to discover at the checkout: the shop takes orders it cannot charge. The severity carried by each key entry cannot say it - which pair is read depends on the test mode, and a key filled but unreadable (a sensitive value encrypted with another secret) is empty to the gateway while the entry looks filled - so the question is put to the gateway itself, which is what isConfigured() is for
class PaymentAlertProvider implements AlertProviderInterface
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentTestModeInterface $testMode,
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getAlerts(): array
    {
        $gateway = $this->gatewayRegistry->getActiveOrNull();

        if (null === $gateway) {
            return [$this->alert('label.payment_gateway_unavailable', 'description.payment_gateway_unavailable', [])];
        }

        if ($gateway->isConfigured()) {
            return [];
        }

        return [$this->alert(
            'label.payment_keys_missing',
            $this->testMode->isEnabled() ? 'description.payment_keys_missing_test' : 'description.payment_keys_missing',
            ['%gateway%' => $gateway->getSlug()],
        )];
    }

    // Sent to the payment group of the config listing, sensitive values shown: every key of that group being sensitive, the listing was empty without it - hence the role too, which is the one that may reveal them
    private function alert(string $label, string $description, array $parameters): array
    {
        return [
            'label' => $this->translator->trans($label, [], 'payment'),
            'description' => $this->translator->trans($description, $parameters, 'payment'),
            'severity' => Config::SEVERITY_DANGER,
            'url' => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(ConfigCrudController::class)
                ->setAction(Action::INDEX)
                ->set('group', Config::GROUP_PAYMENT)
                ->set('showSensitive', 1)
                ->generateUrl(),
            // The same fallback ConfigService applies to a base it cannot read: an empty role here would deny everyone, hiding the alert on the very site whose configs were never loaded
            'role' => (string) ($this->configService->get('site-role-admin') ?: 'ROLE_ADMIN'),
        ];
    }
}
