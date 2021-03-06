<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to display the Payment button using `payment_link(['YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY'])`
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentLink extends AbstractExtension
{
    public function getFunctions()
    {
        return array(
            new TwigFunction(
                'payment_link',
                array($this, 'paymentLink'),
                array(
                    'needs_environment' => true,
                    'is_safe' => array('html'),
                )
            ),
        );
    }

    /**
     * Returns xhtml code for Payment link
     * @return string
     */
    public function paymentLink(Environment $environment, $text = null, $amount = null, $currency = null)
    {
        return $environment->render('@c975LPayment/fragments/paymentLink.html.twig', array(
                'text' => $text,
                'amount' => $amount,
                'currency' => strtolower($currency),
            ));
    }
}
