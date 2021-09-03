<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Email;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\EmailBundle\Service\EmailServiceInterface;
use c975L\PaymentBundle\Entity\Payment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * PaymentEmail class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class PaymentEmail implements PaymentEmailInterface
{
    /**
     * Stores ConfigServiceInterface
     * @var ConfigServiceInterface
     */
    private $configService;

    /**
     * Stores EmailServiceInterface
     * @var EmailServiceInterface
     */
    private $emailService;

    /**
     * Stores current Request
     * @var Request
     */
    private $request;

    /**
     * Stores Environment
     * @var Environment
     */
    private $environment;

    /**
     * Stores TranslatorInterface
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        ConfigServiceInterface $configService,
        EmailServiceInterface $emailService,
        RequestStack $requestStack,
        Environment $environment,
        TranslatorInterface $translator
    )
    {
        $this->configService = $configService;
        $this->emailService = $emailService;
        $this->request = $requestStack->getCurrentRequest();
        $this->environment = $environment;
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Payment $payment, string $amount)
    {
        $subject = $this->configService->getParameter('c975LCommon.site') . ' - ' . $this->translator->trans('label.payment_done', array('%amount%' => $amount), 'payment');

        $this->sendUser($payment, $subject);
        $this->sendSite($payment, $subject);
    }

    /**
     * {@inheritdoc}
     */
    public function sendError(string $object, $data)
    {
        //Stripe error
        if ('stripe' === $object) {
            $errData = $data;
            $body = $this->environment->render('@c975LPayment/emails/errorStripe.html.twig', array(
                'errCode' => $errData['code'],
                'errMessage' => 'PaymentStripe Error : ' . $errData['message'],
                 'locale' => $this->request->getLocale(),
                ));
            $emailData = array(
                'subject' => 'StripeError : ' . $errData['code'],
                'sentFrom' => $this->configService->getParameter('c975LEmail.sentFrom'),
                'sentTo' => $this->configService->getParameter('c975LEmail.sentFrom'),
                'body' => $body,
                'ip' => $this->request->getClientIp(),
                );
        //Validation error
        } elseif ('validation' === $object) {
            $payment = $data;
            $body = $this->environment->render('@c975LPayment/emails/errorValidation.html.twig', array(
                'payment' => $payment,
                 'locale' => $this->request->getLocale(),
                ));
            $emailData = array(
                'subject' => 'PaymentValidation Error',
                'sentFrom' => $this->configService->getParameter('c975LEmail.sentFrom'),
                'sentTo' => $this->configService->getParameter('c975LEmail.sentFrom'),
                'body' => $body,
                'ip' => $this->request->getClientIp(),
                );
        }

        if (isset($emailData)) {
            $this->emailService->send($emailData, $this->configService->getParameter('c975LPayment.database'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendUser(Payment $payment, string $subject)
    {
        $body = $this->environment->render('@c975LPayment/emails/paymentDone.html.twig', array(
            'payment' => $payment,
            'stripeFee' => false,
             'locale' => $this->request->getLocale(),
            ));
        $emailData = array(
            'subject' => $subject,
            'sentFrom' => $this->configService->getParameter('c975LEmail.sentFrom'),
            'sentTo' => $payment->getStripeEmail(),
            'body' => $body,
            'ip' => $this->request->getClientIp(),
            );
        $this->emailService->send($emailData, $this->configService->getParameter('c975LPayment.database'));
    }

    /**
     * {@inheritdoc}
     */
    public function sendSite(Payment $payment, string $subject)
    {
        $body = $this->environment->render('@c975LPayment/emails/paymentDone.html.twig', array(
            'payment' => $payment,
            'stripeFee' => true,
             'locale' => $this->request->getLocale(),
            ));
        $emailData = array(
            'subject' => $subject,
            'sentFrom' => $this->configService->getParameter('c975LEmail.sentFrom'),
            'sentTo' => $this->configService->getParameter('c975LEmail.sentFrom'),
            'body' => $body,
            'ip' => $this->request->getClientIp(),
            );
        $this->emailService->send($emailData, $this->configService->getParameter('c975LPayment.database'));
    }
}
