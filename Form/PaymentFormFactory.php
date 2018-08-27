<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Form;

use Symfony\Component\Form\Form;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Form\PaymentType;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;

/**
 * PaymentFormFactory class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentFormFactory implements PaymentFormFactoryInterface
{
    /**
     * Stores container
     * @var ContainerInterface
     */
    private $container;

    public function __construct(
        ContainerInterface $container,
        FormFactoryInterface $formFactory
    )
    {
        $this->container = $container;
        $this->formFactory = $formFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function create(string $name, Payment $payment)
    {
        switch ($name) {
            case 'free_amount':
                $config = array('gdpr' => $this->container->getParameter('c975_l_payment.gdpr'));
                break;
            default:
                $config = array();
                break;
        }

        return $this->formFactory->create(PaymentType::class, $payment, array('config' => $config));
    }
}
