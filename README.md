PaymentBundle
=============

PaymentBundle does the following:

- Defines form to request payment,
- Stores the transaction in a database table with a unique order id,
- Allows the possibility to add buttons/links for pre-defined payment,
- Allows to define a free amount form for payment (Donation, Consultation, etc.),
- Sends an email, to the user, of the transaction via [c975LEmailBundle](https://github.com/975L/EmailBundle) as `c975LEmailBundle` provides the possibility to save emails in a database, there is an option to NOT do so via this Bundle,
- Sends an email, to the site, containing same information as above + fee and estimated income,
- Creates flash to inform user,
- Display information about payment after transaction.

This Bundle relies on the use of [Stripe](https://stripe.com/) and its [PHP Library](https://github.com/stripe/stripe-php).
**So you MUST have a Stripe account.**
It is also recomended to use this with a SSL certificat to reassure the user.

[Payment Bundle dedicated web page](https://975l.com/en/pages/payment-bundle).

Bundle installation
===================

Step 1: Download the Bundle
---------------------------
Use [Composer](https://getcomposer.org) to install the library
```bash
    composer require c975L/payment-bundle
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
    stripe_secret_key_test : 'YOUR_SECRET_TEST_API_KEY'
    stripe_publishable_key_test: 'YOUR_PUBLISHABLE_TEST_API_KEY'
    stripe_secret_key_live : 'YOUR_SECRET_LIVE_API_KEY'
    stripe_publishable_key_live: 'YOUR_PUBLISHABLE_LIVE_API_KEY'
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
    #Your default currency three letters code
    defaultCurrency: 'EUR' #'EUR'(default)
    #(Optional) Your VAT rate for direct payments without % i.e. 5.5 for 5.5%, or 20 for 20%
    vat: 5.5 #null(default)
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
    type: annotation
    prefix: /
    #Multilingual website use the following
    #prefix: /{_locale}
    #defaults:   { _locale: %locale% }
    #requirements:
    #    _locale: en|fr|es
```

Step 5: Create MySql table
--------------------------
- Use `/Resources/sql/payment.sql` to create the tables `stripe_payment`. The `DROP TABLE` is commented to avoid dropping by mistake.


Step 6: copy images to web folder
---------------------------------
Install images by running
```bash
php bin/console assets:install --symlink
```
It will copy content of folder `Resources/public/images/` to your web folder. They are used to be displayed on the payment form.

You can also have a look at [official badges from Stripe](https://stripe.com/about/resources?locale=fr).

How to use
----------
In your Controller file, you need to create an array containing the following data, then call the service to create the payment, with this array, and finally redirect to the `payment_form` Route.

```php
//Your Controller file
use c975L\PaymentBundle\Service\PaymentService;
//...

//Except amount and currency all the fields are nullable
$paymentData = array(
    'amount' => YOUR_AMOUNT, //Must be an integer in cents
    'currency' => YOUR_CURRENCY, //Coded on 3 letters or use "$this->getParameter('c975_l_payment.defaultCurrency')" to get your default currency
    'action' => YOUR_ACTION, //See below for explanations
    'description' => YOUR_DESCRIPTION,
    'userId' => USER_ID,
    'userIp' => $request->getClientIp(),
    'live' => false|true, //If your product is live or not, different from live config value
    'returnRoute' => 'THE_NAME_OF_YOUR_RETURN_ROUTE', //This Route is defined in your Controller
    'vat' => 'YOUR_VAT_RATE', //Rate value without % i.e. 5.5 for 5.5%, or 20 for 20%
    );
$paymentService = $this->get(\c975L\PaymentBundle\Service\PaymentService::class);
$paymentService->create($paymentData);

//Redirects to the payment
return $this->redirectToRoute('payment_form');

```
`action` is a special field to store (plain text, json, serialize, etc.) the action you want to achieve after the payment is done. It will mainly be used in the `returnRoute`. You can see below an example.

You also need to define a `returnRoute` in your Controller to be able to manage the actions after the payment. It will receive the orderId so you can work with it if needed.
```php
//Your Controller file
use c975L\PaymentBundle\Entity\Payment;

//ReturnRoute after payment, it has been set in the $payment object
//PAYMENT DONE
    /**
     * @Route("/payment-done/{orderId}",
     *      name="payment_done")
     * @Method({"GET", "HEAD"})
     */
    public function paymentDoneAction($orderId)
    {
        //Gets the manager
        $em = $this->getDoctrine()->getManager();

        //Gets Payment
        $payment = $em->getRepository('c975L\PaymentBundle\Entity\Payment')
            ->findOneByOrderIdNotFinished($orderId);

        if ($payment instanceof Payment) {
            //Do the actions
            /*
            * $action should contain anything needed to be achieved after payment is ok.
            * For example, here it contains the result of "json_encode(array('addCredits' => $credits));",
            * as we want to add the number of credits to the user after payment.
            * So, we just decode, test the value and do the job.
            */
            //Adds the credits
            $action = (array) json_decode($payment->getAction());
            if (array_key_exists('addCredits', $action)) {
                //Gets the user
                $user = $em->getRepository('c975LUserBundle:User')
                    ->findOneById($payment->getUserId());

                //Do needed stuff...

                //Adds credits to user
                $user->setCredits($user->getCredits() + $action['addCredits']);
                $em->persist($user);

                //Set payment as finished
                $payment->setFinished(true);
                $em->persist($payment);

                //Persists in database
                $em->flush();

                //Redirects or renders
                return $this->redirectToRoute('YOUR_ROUTE', array(
                ));
            }
        }

        //Redirects to the display of payment
        return $this->redirectToRoute('payment_display', array(
            'orderId' => $orderId,
        ));
    }
```
Use the [testing cards](https://stripe.com/docs/testing) to test before going to production.

Merchant's data
---------------
You need to override the template `fragments/merchantData.html.twig` in your `app/Resources/c975LPaymentBundle/views/fragments/merchantData.html.twig` and indicate there all your official data, such as address, VAT number, etc.

This template will be included in the email sent to the user after its payment.

Mention payment system
----------------------
You can mention the payment system used (i.e. in the footer) by simply include an html fragment with the following code `{% include '@c975LPayment/fragments/paymentSystem.html.twig' %}`. This will include Stripe logo and accepted cards.

Use payment buttons/links
-------------------------
You can add any payment button/link, everywhere you want, by using the Twig extensions with the following code:
```html
{{ payment_button('YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY', 'YOUR_OPTIONAL_STYLES') }}
{{ payment_link('YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY') }}
```
`AMOUNT` is the real amount (i.e. 12.92), **NOT** the amount in cents.

Or you can use it empty, this will lead user fo fill a form to proceed to payment
```html
{{ payment_button() }}
{{ payment_link() }}
```
