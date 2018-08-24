<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorInterface;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\Tools\PaymentToolsInterface;

/**
 * Services related to Payment Tools
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentTools implements PaymentToolsInterface
{
    /**
     * Stores current Request
     * @var RequestStack
     */
    private $request;

    /**
     * Stores Translator
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        RequestStack $requestStack,
        TranslatorInterface $translator
    )
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function createFlash(string $object, array $options = array())
    {
        $style = 'success';

        switch ($object) {
            //Payment done
            case 'payment_done':
                $flash = 'label.payment_done';
                break;
            //Error specific in payment
            case 'error_payment':
                $flash = 'text.error_payment';
                $style = 'danger';
                break;
            //Error generic in payment
            case 'error_payment_generic':
                $flash = 'text.error_payment_generic';
                $style = 'danger';
                break;
            default:
                break;
        }

        if(isset($flash)) {
            $this->request->getSession()
                ->getFlashBag()
                ->add($style, $this->translator->trans($flash, $options, 'payment'))
            ;
        }
    }

    //Creates flash for error
    public function createFlashError(array $errData)
    {
        //Displays specific error message
        if (true === $errData['display']) {
            $this->createFlash('error_payment');
            $this->request->getSession()->getFlashBag()->add('danger', $errMessage);
        //Displays generic message
        } else {
            $this->createFlash('error_payment_generic');
        }
    }
}
