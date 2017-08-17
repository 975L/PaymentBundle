PaymentBundle
=============

PaymentBundle does the following:

- Defines form to request payment,
- Stores the transaction in a database table with a unique ID,
- Sends an email, to the user, of the transaction via [c975LEmailBundle](https://github.com/975L/EmailBundle) as `c975LEmailBundle` provides the possibility to save emails in a database, there is an option to NOT do so via this Bundle,
- Sends an email, to the site, containing same information as above + fee and estimated income,
- Creates flash to inform,
- Display information about payment after transaction.

This Bundle relies on the use of [Stripe](https://stripe.com/) and its [PHP Library](https://github.com/stripe/stripe-php).
**So you MUST have a Stripe account.**
It also recomended to use this with a SSL certificat to reassure the user.

[Payment Bundle dedicated web page](https://975l.com/en/pages/payment-bundle).

Bundle installation
===================

Step 1: Download the Bundle
---------------------------
Add the following to your `composer.json > require section`
```
"require": {
    "c975L/payment-bundle": "1.*"
},
```
Then open a command console, enter your project directory and update composer, by executing the following command, to download the latest stable version of this bundle:

```bash
$ composer update
```

This command requires you to have Composer installed globally, as explained in the [installation chapter](https://getcomposer.org/doc/00-intro.md) of the Composer documentation.

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
Setup your Tinymce API key, optional if you use the cloud version, in `parameters.yml`
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

Then, in the `app/config.yml` file of your project, define the following:

```yml
#Swiftmailer Configuration
swiftmailer:
    transport: "%mailer_transport%"
    host:      "%mailer_host%"
    username:  "%mailer_user%"
    password:  "%mailer_password%"
    spool:     { type: memory }
    auth_mode:  login
    port:       587

#Doctrine Configuration
doctrine:
    dbal:
        driver:   "%database_driver%"
        host:     "%database_host%"
        port:     "%database_port%"
        dbname:   "%database_name%"
        user:     "%database_user%"
        password: "%database_password%"
        charset:  UTF8
    orm:
        auto_generate_proxy_classes: "%kernel.debug%"
        naming_strategy: doctrine.orm.naming_strategy.underscore
        auto_mapping: true

#EmailBundle
c975_l_email:
    sentFrom: 'contact@example.com'

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
    #If you want to use the bitcoin function
    bitcoin: true #false(default)
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

How to use
----------
In your Controller file, you need to create an array containing the following data, then call the service to create the payment, with this array, and finally redirect to the `payment_display` Route.

```php
//Controller file
use c975L\PaymentBundle\Service\StripePaymentService;
//...

//Except amount and currency all the fields are nullable
$stripeData = array(
    'amount' => YOUR_AMOUNT, //Must be an integer in cents
    'currency' => YOUR_CURRENCY, //Coded on 3 letters
    'action' => YOUR_ACTION, //See below for explanations
    'description' => YOUR_DESCRIPTION,
    'userId' => USER_ID,
    'userIp' => USER_IP,
    );
$stripeService = $this->get(StripePaymentService::class);
$stripeService->create($stripeData);

//Redirects to the payment
return $this->redirectToRoute('payment_display');

```
`action` is a special field to store (plain text, json, serialize, etc.) the action you want to achieve after the payment is done. It will mainly be used in the `returnRoute`. You can see below an example.

You also need to define a `returnRoute` in your Controller to be able to manage the actions after the payment. This Route has to be defined in the `config.yml` (see above). It will receive the orderId so you can work with it if needed.
```php
//Controller file
use c975L\PaymentBundle\Entity\StripePayment;

//PAYMENT DONE
    /**
     * @Route("/payment-done/{orderId}",
     *      name="payment_done")
     * @Method({"GET", "HEAD"})
     */
    public function localePaymentDone($orderId)
    {
        //Gets the manager
        $em = $this->getDoctrine()->getManager();

        //Gets Stripe payment
        $stripePayment = $em->getRepository('c975L\PaymentBundle\Entity\StripePayment')
            ->findOneByOrderId($orderId);
        if (!$stripePayment instanceof StripePayment) {
            throw $this->createNotFoundException();
        }

        //StripePayment executed
        if ($stripePayment->getStripeToken() !== null) {
            //Sets stripePayment as finished
            if ($stripePayment->getFinished() !== true) {
                //Gets the user
                $user = $em->getRepository('UserFilesBundle:User')
                    ->findOneById($stripePayment->getUserId());

                //Do the action
                /*
                * $action should contain anything needed to be achieved after payment is ok.
                * For example, here it contains the result of "json_encode(array('addCredits' => $credits));",
                * as we want to add the number of credits to the user after payment.
                * So, we just decode, test the value and do the job.
                */
                $action = (array) json_decode($stripePayment->getAction());

                //Add credits
                if (array_key_exists('addCredits', $action)) {
                    $user->addCredits($action['addCredits']);

                    //Do any other needed stuff...

                    //DO NOT FORGET to update the payment to set it finished
                    $stripePayment->setFinished(true);

                    //Persist in database
                    $em->persist($stripePayment);
                    $em->persist($user);
                    $em->flush();
                }

            //Display the payment data
            return $this->render('@c975LPayment/pages/order.html.twig', array(
                'payment' => $stripePayment,
            ));
            }
        //StripePayment not executed
        } else {
            $stripeService = $this->get(StripePaymentService::class);
            $stripeService->reUse($stripePayment);

            //Display the payment data
            return $this->render('@c975LPayment/pages/orderNotExecuted.html.twig', array(
                'payment' => $stripePayment,
            ));
        }
    }
```
Use the [testing cards](https://stripe.com/docs/testing) to test before going to production.

How to use images
-----------------
**Images are NOT officially supported by Stripe!**
In the Folder `Resources/public/images/` there are two images that can be used to display visual information. `stripe-cards.jpg` has to be installed, except if you override `payment.html.twig`, as ths picture is used in the template.

To install the images simply run
```bash
php bin/console assets:install
```
Then tou can use them via
```html
<img src="{{ asset('bundles/c975lpayment/images/stripe-cards.jpg') }}" alt="stripe cards" />
<img src="{{ asset('bundles/c975lpayment/images/stripe.png') }}" alt="stripe" />
```