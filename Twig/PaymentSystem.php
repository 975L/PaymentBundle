<?php
/*
 * (c) 2018: 975l <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

class PaymentSystem extends \Twig_Extension
{
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction(
                'payment_system',
                array($this, 'paymentSystem'),
                array('needs_environment' => true)
            ),
        );
    }

    public function paymentSystem(\Twig_Environment $environment)
    {
        return $environment->render('@c975LPayment/fragments/paymentSystem.html.twig');
    }
}