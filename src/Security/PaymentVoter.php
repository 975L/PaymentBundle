<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Security;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Payment;
use LogicException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Payment access
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentVoter extends Voter
{
    /**
     * Used for access to config
     * @var string
     */
    final public const CONFIG = 'c975LPayment-config';

    /**
     * Used for access to dashboard
     * @var string
     */
    final public const DASHBOARD = 'c975LPayment-dashboard';

    /**
     * Contains all the available attributes to check with in supports()
     * @var array
     */
    private const ATTRIBUTES = [self::CONFIG, self::DASHBOARD];

    public function __construct(
        /**
         * Stores ConfigServiceInterface
         */
        private readonly ConfigServiceInterface $configService,
        /**
         * Stores AccessDecisionManagerInterface
         */
        private readonly AccessDecisionManagerInterface $decisionManager
    )
    {
    }

    /**
     * {@inheritdoc}
     */
    protected function supports($attribute, $subject): bool
    {
        if (null !== $subject) {
            return $subject instanceof Payment && in_array($attribute, self::ATTRIBUTES);
        }

        return in_array($attribute, self::ATTRIBUTES);
    }

    /**
     * {@inheritdoc}
     */
    protected function voteOnAttribute($attribute, $subject, TokenInterface $token): bool
    {
        //Defines access rights
        switch ($attribute) {
            case self::CONFIG:
            case self::DASHBOARD:
                return $this->decisionManager->decide($token, array($this->configService->getParameter('c975LPayment.roleNeeded', 'c975l/payment-bundle')));
                break;
        }

        throw new LogicException('Invalid attribute: ' . $attribute);
    }
}
