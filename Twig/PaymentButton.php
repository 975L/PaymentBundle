<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

/**
 * Twig extension to display the Payment button using `payment_button(['YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY', 'YOUR_OPTIONAL_STYLES'])
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentButton extends \Twig_Extension
{
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction(
                'payment_button',
                array($this, 'paymentButton'),
                array(
                    'needs_environment' => true,
                    'is_safe' => array('html'),
                )
            ),
        );
    }

    /**
     * Returns xhtml code for Payment button
     * @return string
     */
    public function paymentButton(\Twig_Environment $environment, $text = null, $amount = null, $currency = null, $style = 'btn btn-lg btn-primary')
    {
        return $environment->render('@c975LPayment/fragments/paymentButton.html.twig', array(
                'text' => $text,
                'amount' => $amount,
                'currency' => strtolower($currency),
                'style' => $style,
            ));
    }
}