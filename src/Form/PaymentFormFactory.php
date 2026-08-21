<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Translation\TranslatableMessage;

class PaymentFormFactory implements PaymentFormFactoryInterface
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function create(string $name, $object): FormInterface
    {
        $config = match ($name) {
            'coordinates' => [
                'touUrl' => new TranslatableMessage(
                    'label.accept_tou',
                    ['%touUrl%' => $this->configService->get('url-terms-of-use')],
                    'site',
                ),
                'tosUrl' => new TranslatableMessage(
                    'label.accept_tos',
                    ['%tosUrl%' => $this->configService->get('url-terms-of-sales')],
                    'site',
                ),
            ],
            default => throw new \InvalidArgumentException(sprintf('Unknown form "%s"', $name)),
        };

        return $this->formFactory->create(CoordinatesType::class, $object, ['config' => $config]);
    }
}
