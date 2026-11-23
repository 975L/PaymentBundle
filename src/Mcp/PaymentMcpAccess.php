<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

// What every payment tool checks before answering: the account administers the payments, as on the back office, and a day it is given is a real one
class PaymentMcpAccess
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
    ) {
    }

    // Payments, codes and orders are the admin's business, as on the back office
    public function guard(): void
    {
        if (!$this->security->isGranted((string) $this->configService->get('site-role-admin'))) {
            throw new ToolCallException('This account may not read the payments.');
        }
    }

    // A day written YYYY-MM-DD, null when none is given - one PHP would roll over refused
    public function day(?string $day): ?\DateTimeImmutable
    {
        $value = trim((string) $day);
        if ('' === $value) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            throw new ToolCallException(sprintf('"%s" is not a day written YYYY-MM-DD.', $value));
        }

        return $parsed;
    }
}
