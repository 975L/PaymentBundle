<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Management;

use c975L\ConfigBundle\Management\HealthCheckAdviceBuilder;
use c975L\ConfigBundle\Management\HealthCheckAdviceProviderInterface;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use c975L\PaymentBundle\Controller\Management\PaymentCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// The orders behind each of BasketIntegrityHealthCheckProvider's counts, one link per order: "three orders were charged and never delivered" is only worth reading if the next click opens the first of the three
class BasketIntegrityHealthCheckAdviceProvider implements HealthCheckAdviceProviderInterface
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildAdvice(array $results): array
    {
        $advice = [];

        foreach ($results as $result) {
            if (BasketIntegrityHealthCheckProvider::KIND !== $result->getKind()) {
                continue;
            }

            $offenders = ($result->getDetails() ?? [])['offenders'] ?? [];
            if ([] === $offenders) {
                continue;
            }

            $advice[HealthCheckAdviceBuilder::key($result)] = [[
                'text' => $this->translator->trans('label.health_check_advice_basket_offenders', ['%count%' => \count($offenders)], 'payment'),
                'url' => null,
                'items' => array_map($this->item(...), $offenders),
            ]];
        }

        return $advice;
    }

    // The order's own screen when there is an order, the payment's when the charge answers for none - which is itself what that row is reporting
    private function item(array $offender): array
    {
        $basketId = $offender['basketId'] ?? null;
        $paymentId = $offender['paymentId'] ?? null;

        return [
            'text' => $this->translator->trans('label.health_check_advice_basket_offender', [
                '%order%' => $offender['number'] ?? ('#' . ($basketId ?? $paymentId ?? '?')),
                '%info%' => $offender['info'] ?? '',
            ], 'payment'),
            'url' => match (true) {
                null !== $basketId => $this->detailUrl(BasketCrudController::class, $basketId),
                null !== $paymentId => $this->detailUrl(PaymentCrudController::class, $paymentId),
                default => null,
            },
            'label' => null,
        ];
    }

    // Both CRUDs disable edition - an order is an accounting record, and what it is opened for is to be read
    private function detailUrl(string $controller, int $entityId): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController($controller)
            ->setAction(Action::DETAIL)
            ->setEntityId($entityId)
            ->generateUrl();
    }
}
