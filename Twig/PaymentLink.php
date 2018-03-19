<?php
/*
 * (c) 2018: 975l <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

class PaymentLink extends \Twig_Extension
{
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction(
                'payment_link',
                array($this, 'paymentLink'),
                array(
                    'needs_environment' => true,
                    'is_safe' => array('html'),
                )
            ),
        );
    }

    public function paymentLink(\Twig_Environment $environment, $text = null, $amount = null, $currency = null)
    {
        //Defines link
        return $environment->render('@c975LPayment/fragments/paymentLink.html.twig', array(
                'text' => $text,
                'amount' => $amount,
                'currency' => strtolower($currency),
            ));
    }
}