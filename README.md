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

[PaymentBundle dedicated web page](https://975l.com/en/pages/payment-bundle).

[PaymentBundle API documentation](https://975l.com/apidoc/c975L/PaymentBundle.html).

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
    #(Optional) Your Stripe Fee rate without % i.e. 1.4 for 1.4%
    stripeFeePercentage: 1.4 #1.4(default)
    #(Optional) Your Stripe Fee fixed part in cents
    stripeFeeFixed: 25 #25(default)
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
    #defaults:   { _locale: '%locale%' }
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
The process is the following:
- User selects a product,
- User click tp pay,
- Payment in created in DB,
- User is redirected to Payment form,
- User pays,
- User is redirected to returnRoute,
- Actions are executed to deliver product (if payment successful),
- User is redirected to final confirmation or delivery product page.

To achieve this, you have to define 2 Controller Routes and 2 Services method (+ Interface) (while you can do all of this in Controller, Best Practices recommend to keep Controller methods skinny).

Here are the examples for those 3 files:

```php
//Your ServiceInterface file
namespace App\Service\YourPaymentService;

use c975L\PaymentBundle\Entity\Payment;

interface YourPaymentServiceInterface
{
    public function payment($yourNeededData);

    public function validate(Payment $payment);
}
```

```php
//Your Service file
namespace App\Service\YourPaymentService;

use App\Service\YourPaymentServiceInterface;
use c975L\PaymentBundle\Service\PaymentServiceInterface;

class YourPaymentService implements YourPaymentServiceInterface
{
    public function payment(PaymentServiceInterface $paymentService, $yourNeededData)
    {
        /**
         * Except amount and currency all the fields are nullable
         * You may use the data define in `$yourNeededData`
         */
        $paymentData = array(
            'amount' => YOUR_AMOUNT, //Must be an integer in cents
            'currency' => YOUR_CURRENCY, //Coded on 3 letters or use "$this->getParameter('c975_l_payment.defaultCurrency')" to get your default currency
            'action' => YOUR_ACTION, //Store the action to achieve after the payment. Mainly used by `returnRoute`. As a string, you can store plain text, json, etc.
            'description' => YOUR_DESCRIPTION,
            'userId' => USER_ID,
            'userIp' => $request->getClientIp(),
            'live' => false|true, //If your product is live or not, different from live config value
            'returnRoute' => 'THE_NAME_OF_YOUR_RETURN_ROUTE', //This Route is defined in your Controller
            'vat' => 'YOUR_VAT_RATE', //Rate value without % i.e. 5.5 for 5.5%, or 20 for 20%
            );
        $paymentService->create($paymentData);
    }

    public function validate(Payment $payment)
    {
        /**
         * For example if `$payment->getAction()` contains the result of "json_encode(array('addCredits' => 10));"
         */
        $action = (array) json_decode($payment->getAction());
        if (array_key_exists('addCredits', $action)) {
            //Gets the user
            $user = $em->getRepository('c975LUserBundle:User')
                ->findOneById($payment->getUserId());

            //Adds credits to user
            $user->setCredits($user->getCredits() + $action['addCredits']);
            $em->persist($user);

            //Set payment as finished
            $payment->setFinished(true);
            $em->persist($payment);
            $em->flush();
        }
    }
}
```

```php
//Your Controller file
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use c975L\PaymentBundle\Entity\Payment;
use App\Service\YourPaymentServiceInterface;;

    /**
     * Route used to proceed to payment
     * @return Response
     *
     * @Route("proceed-to-payment",
     *     name="proceed_to_payment")
     */
    public function proceedToPayment(YourPaymentServiceInterface $yourPaymentService)
    {
        //Creates the Payment
        $yourPaymentService->payment();

        //Redirects to the payment form
        return $this->redirectToRoute('payment_form');
    }

    /**
     * Return Route used after payment
     * @return Redirect
     * @throws NotFoundHttpException
     *
     * @Route("/payment-done/{orderId}",
     *      name="payment_done")
     * @Method({"GET", "HEAD"})
     * @ParamConverter("payment",
     *      options={
     *          "repository_method" = "findOneByOrderIdNotFinished",
     *          "mapping": {"orderId": "orderId"},
     *          "map_method_signature" = true
     *      })
     */
    public function paymentDone(YourPaymentServiceInterface $yourPaymentService, Payment $payment)
    {
        //Validates the Payment
        $yourPaymentService->validate($payment);

        //Redirects or renders
        return $this->redirectToRoute('YOUR_ROUTE');
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
You can add any payment button/link, wherever you want, by using the Twig extensions with the following code:
```twig
{{ payment_button('YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY', 'YOUR_OPTIONAL_STYLES') }}
{{ payment_link('YOUR_TEXT_TO_DISPLAY', AMOUNT, 'CURRENCY') }}
```
`AMOUNT` is the real amount (i.e. 12.92), **NOT** the amount in cents.

Or you can use it empty, this will lead user fo fill a form to proceed to payment
```twig
{{ payment_button() }}
{{ payment_link() }}
```

**If this project help you to reduce time to develop, you can [buy me a coffee](https://www.buymeacoffee.com/LaurentMarquet) :)**