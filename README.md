PaymentBundle
=============

PaymentBundle does the following:

- Defines form to request payment,
- Stores the transaction in a database table with a unique order id,
- Sends an email, to the user, of the transaction via [c975LEmailBundle](https://github.com/975L/EmailBundle) as `c975LEmailBundle` provides the possibility to save emails in a database, there is an option to NOT do so via this Bundle,
- Sends an email, to the site, containing same information as above + fee and estimated income,
- Creates flash to inform user,
- Display information about payment after transaction.

This Bundle relies on the use of [Stripe](https://stripe.com/) and its [PHP Library](https://github.com/stripe/stripe-php).
**So you MUST have a Stripe account.**
It also recomended to use this with a SSL certificat to reassure the user.

[Payment Bundle dedicated web page](https://975l.com/en/pages/payment-bundle).

Bundle installation
===================

Step 1: Download the Bundle
---------------------------
Use [Composer](https://getcomposer.org) to install the library
```bash
    composer require c975L/payment-bundle
},
```

Step 2: Enable the Bundles
--------------------------
Then, enable the bundles by adding them to the list of registered bundles in the `app/AppKernel.php` file of your project:

```php
<?php
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = [
            // ...
            new c975L\EmailBundle\c975LEmailBundle(),
            new c975L\PaymentBundle\c975LPaymentBundle(),
        ];
    }
}
```

Step 3: Configure the Bundle
----------------------------
Setup your Stripe API keys, in `parameters.yml`
```yml
    #Your Stripe Api keys
    stripe_secret_key_test : YOUR_API_KEY
    stripe_publishable_key_test: YOUR_API_KEY
    stripe_secret_key_live : YOUR_API_KEY
    stripe_publishable_key_live: YOUR_API_KEY
```

And then in `parameters.yml.dist`
```yml
    stripe_secret_key_test : ~
    stripe_publishable_key_test: ~
    stripe_secret_key_live : ~
    stripe_publishable_key_live: ~
```

Check [c975LEmailBundle](https://github.com/975L/EmailBundle)  for its specific configuration
Then, in the `app/config.yml` file of your project, define the following:

```yml
#PaymentBundle
c975_l_payment:
    #The site name that will appear on the payment form
    site: 'example.com'
    #If your payment are live or should use the test keys
    live: true #Default false
    #(Optional) The Route to return after having charged user's card
    returnRoute: 'payment_done' #null(default)
    #(Optional) The Timezone as per default it will be UTC
    timezone: 'Europe/Paris' #null(default)
    #If you want to save the email sent to the database linked to c975L/EmailBundle, see https://github.com/975L/EmailBundle
    database: true #false(default)
    #If you want to display an image in the Stripe form (recommended)
    image: 'images/logo.png' #null(default)
    #If you want to use the zip code function
    zipCode: false #true(default)
    #If you want to use the alipay function
    alipay: true #false(default)
    #User's role needed to enable access to the display of payments
    roleNeeded: 'ROLE_ADMIN'
```

Step 4: Enable the Routes
-------------------------
Then, enable the routes by adding them to the `app/config/routing.yml` file of your project:

```yml
c975_l_payment:
    resource: "@c975LPaymentBundle/Controller/"
    type:     annotation
    #Multilingual website use: prefix: /{_locale}
    prefix:   /
```

Step 5: Create MySql table
--------------------------
- Use `/Resources/sql/payment.sql` to create the tables `stripe_payment`. The `DROP TABLE` is commented to avoid dropping by mistake.


Ste 6: copy images to web folder
--------------------------------
Install images by running
```bash
php bin/console assets:install
```
It will copy content of folder `Resources/public/images/` to your web folder. They are used to be displayed on the payment form.
You can also use them in the footer, for this, you may simply use the Twig Extension by pasting the following in iyur footer (or wherever you want).
```html
{{ payment_system() }}
```
You can also have a look at [official badges from Stripe](https://stripe.com/about/resources?locale=fr).

How to use
----------
In your Controller file, you need to create an array containing the following data, then call the service to create the payment, with this array, and finally redirect to the `payment_form` Route.

```php
//Controller file
use c975L\PaymentBundle\Service\PaymentService;
//...

//Except amount and currency all the fields are nullable
$paymentData = array(
    'amount' => YOUR_AMOUNT, //Must be an integer in cents
    'currency' => YOUR_CURRENCY, //Coded on 3 letters
    'action' => YOUR_ACTION, //See below for explanations
    'description' => YOUR_DESCRIPTION,
    'userId' => USER_ID,
    'userIp' => USER_IP,
    );
$paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);
$paymentService->create($paymentData);

//Redirects to the payment
return $this->redirectToRoute('payment_form');

```
`action` is a special field to store (plain text, json, serialize, etc.) the action you want to achieve after the payment is done. It will mainly be used in the `returnRoute`. You can see below an example.

You also need to define a `returnRoute` in your Controller to be able to manage the actions after the payment. This Route has to be defined in the `config.yml` (see above). It will receive the orderId so you can work with it if needed.
```php
//Controller file
use c975L\PaymentBundle\Entity\Payment;

//PAYMENT DONE
    /**
     * @Route("/payment-done/{orderId}",
     *      name="payment_done")
     * @Method({"GET", "HEAD"})
     */
    public function paymentDone($orderId)
    {
        //Gets the manager
        $em = $this->getDoctrine()->getManager();

        //Gets Stripe payment
        $payment = $em->getRepository('c975L\PaymentBundle\Entity\StripePayment')
            ->findOneByOrderId($orderId);
        if (!$payment instanceof StripePayment) {
            throw $this->createNotFoundException();
        }

        //StripePayment executed
        if ($payment->getStripeToken() !== null) {
            //Sets Payment as finished
            if ($payment->getFinished() !== true) {
                //Gets the user
                $user = $em->getRepository('UserFilesBundle:User')
                    ->findOneById($payment->getUserId());

                //Do the action
                /*
                * $action should contain anything needed to be achieved after payment is ok.
                * For example, here it contains the result of "json_encode(array('addCredits' => $credits));",
                * as we want to add the number of credits to the user after payment.
                * So, we just decode, test the value and do the job.
                */
                $action = (array) json_decode($payment->getAction());

                //Add credits
                if (array_key_exists('addCredits', $action)) {
                    $user->addCredits($action['addCredits']);

                    //Do any other needed stuff...

                    //DO NOT FORGET to update the payment to set it finished
                    $payment->setFinished(true);

                    //Persist in database
                    $em->persist($payment);
                    $em->persist($user);
                    $em->flush();
                }

                //Redirects to the order page, but you can change it to your own
                return $this->redirectToRoute('payment_confirm', array(
                    'orderId' => $orderId,
                ));
            //Payment already finished (happens only if stop loading an refresh of the order page)
            } else {
                return $this->redirectToRoute('payment_confirm', array(
                    'orderId' => $orderId,
                ));
            }
        //StripePayment not executed
        } else {
            $paymentService = $this->get(PaymentService::class);
            $paymentService->reUse($payment);

            //Display the payment data
            return $this->render('@c975LPayment/pages/orderNotExecuted.html.twig', array(
                'payment' => $payment,
            ));
        }
    }
```
Use the [testing cards](https://stripe.com/docs/testing) to test before going to production.
