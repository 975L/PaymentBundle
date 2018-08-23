<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\PaymentServiceInterface;

class PaymentService implements PaymentServiceInterface
{
    private $container;
    private $em;
    private $emailService;
    private $request;
    private $templating;

    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
        \Doctrine\ORM\EntityManagerInterface $em,
        \c975L\EmailBundle\Service\EmailService $emailService,
        \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        \Twig_Environment $templating
    )
    {
        $this->container = $container;
        $this->em = $em;
        $this->emailService = $emailService;
        $this->request = $requestStack->getCurrentRequest();
        $this->templating = $templating;
    }

    //Creates the payment
    public function create($data)
    {
        //Payment or product under test
        $live = isset($data['live']) ? $data['live'] : $this->container->getParameter('c975_l_payment.live');
        if ($this->container->getParameter('c975_l_payment.live') === false || $live === false) {
            $data['description'] = '(TEST) ' . $data['description'];
            $data['live'] = false;
        //Payment live
        } else {
            $data['live'] = true;
        }

        //Creates payment
        $payment = new Payment($data, $this->container->getParameter('c975_l_payment.timezone'));

        //Persists data in DB
        $this->em->persist($payment);
        $this->em->flush();

        //Saves in the session
        $this->request->getSession()->set('stripe', $payment);
    }

    //Creates flash
    public function createFlash($payment)
    {
        $flash = $this->container->get('translator')->trans('label.payment_done', array(), 'payment');
        $flash .= ' (' . $payment->getAmount() / 100 . ' ' . $payment->getCurrency() . ')';
        $this->request->getSession()->getFlashBag()->add('success', $flash);
    }

    //Creates flash for error
    public function createFlashError($displayError)
    {
        if ($displayError === true) {
            $flash = $this->container->get('translator')->trans('text.error_payment', array(), 'payment');
            $this->request->getSession()->getFlashBag()->add('danger', $flash);
            $this->request->getSession()->getFlashBag()->add('danger', $errMessage);
        } else {
            $flash = $this->container->get('translator')->trans('text.error_payment_generic', array(), 'payment');
            $this->request->getSession()->getFlashBag()->add('danger', $flash);
        }
    }

    //Creates the charge on Stripe's servers - This will charge the user's card
    public function charge($stripeSession)
    {
        //Grabs data from transaction
        $stripeToken = $this->request->get('stripeToken');
        $stripeTokenType = $this->request->get('stripeTokenType');
        $stripeEmail = $this->request->get('stripeEmail');

        //Defines payment
        $paymentData = array(
            'amount' => $stripeSession->getAmount(),
            'currency' => $stripeSession->getCurrency(),
            'source' => $stripeToken,
            'description' => $stripeSession->getDescription(),
            'metadata' => array('order_id' => $stripeSession->getOrderId())
        );

        try {
            //Do the Stripe transaction
            \Stripe\Stripe::setApiKey($this->getSecretKey($stripeSession->getLive()));
            \Stripe\Charge::create($paymentData);

            //Updates data for payment done
            $payment = $this->em
                ->getRepository('c975L\PaymentBundle\Entity\Payment')
                ->findOneByOrderId($stripeSession->getOrderId());
            if ($payment instanceof Payment) {
                $payment
                    ->setStripeFee((int) (($payment->getAmount() * $this->container->getParameter('c975_l_payment.stripeFeePercentage') / 100) + $this->container->getParameter('c975_l_payment.stripeFeeFixed')))
                    ->setStripeToken($stripeToken)
                    ->setStripeTokenType($stripeTokenType)
                    ->setStripeEmail($stripeEmail)
                ;

                //Persist in DB
                $this->em->persist($payment);
                $this->em->flush();

                //Sends emails (user + site)
                $this->sendEmail($payment);

                //Creates flash
                $this->createFlash($payment);
            }

            //Deletes data in session
            $this->request->getSession()->remove('stripe');

            return $stripeSession->getOrderId();
        //Errors
        } catch (\Stripe\Error\Card $e) {
            //Since it's a decline, \Stripe\Error\Card will be caught
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe01 - Card';
            $displayError = true;
        } catch (\Stripe\Error\RateLimit $e) {
            //Too many requests made to the API too quickly
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe02 - RateLimit';
            $displayError = true;
        } catch (\Stripe\Error\InvalidRequest $e) {
            //Invalid parameters were supplied to Stripe's API
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe03 - InvalidRequest';
            $displayError = false;
        } catch (\Stripe\Error\Authentication $e) {
            //Authentication with Stripe's API failed (maybe you changed API keys recently)
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe04 - Authentication';
            $displayError = false;
        } catch (\Stripe\Error\ApiConnection $e) {
            //Network communication with Stripe failed
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe05 - ApiConnection';
            $displayError = true;
        } catch (\Stripe\Error\Base $e) {
            //Display a very generic error to the user, and maybe send yourself an email
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe06 - Base';
            $displayError = false;
        } catch (Exception $e) {
            //Something else happened, completely unrelated to Stripe
            $errMessage = $e->getJsonBody()['error']['message'];
            $errCode = 'ErrStripe07 - Other';
            $displayError = false;
        }

        //Sends an email for error
        $this->sendEmailError($errCode, $errMessage);

        //Creates flash for error
        $this->createFlashError($displayError);

        return false;
    }

    //Get publishable key
    public function getPublishableKey($live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_publishable_key_test')) {
                throw new InvalidArgumentException('No stripe_publishable_key_test');
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_test');
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_publishable_key_live')) {
                throw new InvalidArgumentException('No stripe_publishable_key_live');
            }
            $stripePublishableKey = $this->container->getParameter('stripe_publishable_key_live');
        }

        return $stripePublishableKey;
    }

    //Get secret key
    public function getSecretKey($live = false)
    {
        //Stripe key - Tests payments
        if ($live === false) {
            if (!$this->container->hasParameter('stripe_secret_key_test')) {
                throw new InvalidArgumentException('No stripe_secret_key_test');
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_test');
        //Stripe key - Live payments
        } else {
            if (!$this->container->hasParameter('stripe_secret_key_live')) {
                throw new InvalidArgumentException('No stripe_secret_key_live');
            }
            $stripeSecretKey = $this->container->getParameter('stripe_secret_key_live');
        }

        return $stripeSecretKey;
    }

    //Re-use a Stripe payment not executed
    public function reUse($payment)
    {
        //Saves in the session
        $session = $this->request->getSession();
        $session->set('stripe', $payment);
    }

    //Sends emails
    public function sendEmail($payment)
    {
        $this->sendEmailUser($payment);
        $this->sendEmailSite($payment);
    }

    //Sends an email on error
    public function sendEmailError($errCode, $errMessage)
    {
        $errMessage = 'Stripe err : ' . $errMessage;
        $body = $this->templating->render('@c975LPayment/emails/errorStripe.html.twig', array(
            'errCode' => $errCode,
            'errMessage' => $errMessage,
             '_locale' => $this->request->getLocale(),
            ));
        $emailData = array(
            'subject' => 'StripeError : ' . $errCode,
            'sentFrom' => $this->container->getParameter('c975_l_email.sentFrom'),
            'sentTo' => $this->container->getParameter('c975_l_email.sentFrom'),
            'body' => $body,
            'ip' => $this->request->getClientIp(),
            );
        $this->emailService->send($emailData, $this->container->getParameter('c975_l_payment.database'));
    }

    //Sends email for user
    public function sendEmailUser($payment)
    {
        //Creates email
        $subject = $this->container->getParameter('c975_l_payment.site');
        $subject .= ' - ' . $this->container->get('translator')->trans('label.payment_done', array(), 'payment');
        $subject .= ' (' . $payment->getAmount() / 100 . ' ' . $payment->getCurrency() . ')';
        $body = $this->templating->render('@c975LPayment/emails/paymentDone.html.twig', array(
            'payment' => $payment,
            'stripeFee' => false,
             '_locale' => $this->request->getLocale(),
            ));
        $emailData = array(
            'subject' => $subject,
            'sentFrom' => $this->container->getParameter('c975_l_email.sentFrom'),
            'sentTo' => $payment->getStripeEmail(),
            'body' => $body,
            'ip' => $this->request->getClientIp(),
            );
        $this->emailService->send($emailData, $this->container->getParameter('c975_l_payment.database'));
    }

    //Sends email to the site
    public function sendEmailSite($payment)
    {
        //Creates email
        $subject = $this->container->getParameter('c975_l_payment.site');
        $subject .= ' - ' . $this->container->get('translator')->trans('label.payment_done', array(), 'payment');
        $subject .= ' (' . $payment->getAmount() / 100 . ' ' . $payment->getCurrency() . ')';
        $body = $this->templating->render('@c975LPayment/emails/paymentDone.html.twig', array(
            'payment' => $payment,
            'stripeFee' => true,
             '_locale' => $this->request->getLocale(),
            ));
        $emailData = array(
            'subject' => $subject,
            'sentFrom' => $this->container->getParameter('c975_l_email.sentFrom'),
            'sentTo' => $this->container->getParameter('c975_l_email.sentFrom'),
            'body' => $body,
            'ip' => $this->request->getClientIp(),
            );
        $this->emailService->send($emailData, $this->container->getParameter('c975_l_payment.database'));
    }
}
