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
        return [...$this->gatewayAlerts(), ...$this->archiveAlerts()];
    }

    /**
     * Whether the shop can charge at all.
     *
     * @return list<array{label: string, description: ?string, severity: Config::SEVERITY_*, url: string, role?: string}>
     */
    private function gatewayAlerts(): array
    {
        // Said whatever else the shop can charge with: "payment-gateway" naming a provider no bundle registers is a typo, and the provider the basket pre-selects is then not the one the shopkeeper meant
        $gateway = $this->gatewayRegistry->getActiveOrNull();
        if (null === $gateway) {
            return [$this->alert('label.payment_gateway_unavailable', 'description.payment_gateway_unavailable', [], Config::SEVERITY_DANGER, Config::GROUP_PAYMENT, true)];
        }

        // A shop charges as long as one provider holds its keys, the customer picking between those that do - the default alone holding none is no longer a shop that cannot sell
        if ([] !== $this->gatewayRegistry->getOffered()) {
            return [];
        }

        return [$this->alert(
            'label.payment_keys_missing',
            $this->testMode->isEnabled() ? 'description.payment_keys_missing_test' : 'description.payment_keys_missing',
            ['%gateway%' => $gateway->getSlug()],
            Config::SEVERITY_DANGER,
            Config::GROUP_PAYMENT,
            true,
        )];
    }

    /**
     * Whether the shop keeps a copy of what it sends.
     *
     * "shop-email-bcc" is the only record a shopkeeper has of the order confirmations and download links that went
     * out: nothing else keeps them, and an order disputed months later is then answered with the buyer's word alone.
     * Left empty it is silent - the email simply leaves without its blind copy - which is what this says out loud.
     *
     * @return list<array{label: string, description: ?string, severity: Config::SEVERITY_*, url: string, role?: string}>
     */
    private function archiveAlerts(): array
    {
        if ('' !== trim((string) $this->configService->get('shop-email-bcc'))) {
            return [];
        }

        return [$this->alert(
            'label.shop_email_bcc_missing',
            'description.shop_email_bcc_missing',
            [],
            Config::SEVERITY_WARNING,
            Config::GROUP_EMAIL,
            false,
        )];
    }

    /**
     * Sent to the group of the config listing holding the entry to fill in.
     *
     * "showSensitive" for the payment group alone, and the role with it: every key of that group being sensitive,
     * the listing was empty without it - and the role is the one that may reveal them.
     *
     * @param array<string, string> $parameters
     *
     * @return array{label: string, description: ?string, severity: Config::SEVERITY_*, url: string, role?: string}
     */
    private function alert(string $label, string $description, array $parameters, string $severity, string $group, bool $showSensitive): array
    {
        $url = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(ConfigCrudController::class)
            ->setAction(Action::INDEX)
            ->set('group', $group);

        if ($showSensitive) {
            $url->set('showSensitive', 1);
        }

        return [
            'label' => $this->translator->trans($label, [], 'payment'),
            'description' => $this->translator->trans($description, $parameters, 'payment'),
            'severity' => $severity,
            'url' => $url->generateUrl(),
            // The same fallback ConfigService applies to a base it cannot read: an empty role here would deny everyone, hiding the alert on the very site whose configs were never loaded
            'role' => (string) ($this->configService->get('site-role-admin') ?: 'ROLE_ADMIN'),
        ];
    }
}
