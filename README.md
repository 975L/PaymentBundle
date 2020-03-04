# PaymentBundle

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

## Bundle installation

### Step 1: Download the Bundle

**v3.x works with Symfony 4.x. Use v2.x for Symfony 3.x**
Use [Composer](https://getcomposer.org) to install the library

```bash
    composer require c975L/payment-bundle
```

### Step 2: Configure the Bundle

Check dependencies for their configuration:

- [Symfony Mailer](https://github.com/symfony/mailer)
- [Doctrine](https://github.com/doctrine/DoctrineBundle)
- [KnpPaginatorBundle](https://github.com/KnpLabs/KnpPaginatorBundle)
- [c975LEmailBundle](https://github.com/975L/EmailBundle)
- [Stripe PHP Library](https://github.com/stripe/stripe-php)

c975LPaymentBundle uses [c975L/ConfigBundle](https://github.com/975L/ConfigBundle) to manage configuration parameters. Use the Route "/payment/config" with the proper user role to modify them.

### Step 3: Enable the Routes

Then, enable the routes by adding them to the `/config/routes.yaml` file of your project:

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

### Step 4: Create MySql table

You can use `php bin/console make:migration` to create the migration file as documented in [Symfony's Doctrine docs](https://symfony.com/doc/current/doctrine.html) OR use `/Resources/sql/payment.sql` to create the tables `stripe_payment`. The `DROP TABLE` is commented to avoid dropping by mistake.

### Step 5: copy images to web folder
Install images by running

```bash
php bin/console assets:install --symlink
```

It will copy content of folder `Resources/public/images/` to your web folder. They are used to be displayed on the payment form.

You can also have a look at [official badges from Stripe](https://stripe.com/about/resources?locale=fr).

### How to use

The process is the following:

- User selects a product,
- User clicks to pay,
- Payment is created in DB,
- User is redirected to Payment form,
- User pays,
- User is redirected to returnRoute,
- Actions are executed to deliver product (if payment successful or sends an error),
- User is redirected to final confirmation or delivery product page.

To achieve this, you have to define 2 Controller Routes and 2 Services methods (+ Interface) (while you can do all of this in Controller, Best Practices recommend to keep Controller methods skinny).

Here are the examples for those files:

```php
//YourProductServiceInterface file
namespace App\Service;

use c975L\PaymentBundle\Entity\Payment;

interface YourProductServiceInterface
{
    public function validate(Payment $payment);
}
```

```php
//Your ProductService file
namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Service\YourProductServiceInterface;

class YourProductService implements YourProductServiceInterface
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function validate(Payment $payment)
    {
        /**
         * For example if `$payment->getAction()` contains the result of "json_encode(array('addCredits' => 10));"
         */
        $action = (array) json_decode($payment->getAction());
        if (array_key_exists('addCredits', $action)) {
            //Gets the user
            $user = $this->em->getRepository('c975LUserBundle:User')
                ->findOneById($payment->getUserId());

            //Adds credits to user
            $user->setCredits($user->getCredits() + $action['addCredits']);
            $this->em->persist($user);

            //Set payment as finished
            $payment->setFinished(true);
            $this->em->persist($payment);
            $this->em->flush();

            return true;
        }

        return false;
    }
}
```

```php
//YourPaymentServiceInterface file
namespace App\Service;

interface YourPaymentServiceInterface
{
    public function payment($yourNeededData);
}
```

```php
//Your PaymentService file
namespace App\Service;

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
            'currency' => YOUR_CURRENCY, //Coded on 3 letters or use "$paymentService->getParameter('c975LPayment.defaultCurrency')" to get your default currency
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
}
```

```php
//Your Controller file
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Service\PaymentServiceInterface;
use App\Service\YourPaymentServiceInterface;

class PaymentController extends AbstractController
{
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
     *    name="payment_done",
     *    methods={"HEAD", "GET"})
     */
    public function paymentDone(YourProductServiceInterface $yourProductService, PaymentServiceInterface $paymentService, Payment $payment)
    {
        //Validates the Payment
        $validation = $yourProductService->validate($payment);

        //Redirects or renders
        if ($validation) {
            return $this->redirectToRoute('YOUR_ROUTE');
        }

         //Payment has been done but product was not validated
        $paymentService->error($payment);

        return $this->redirectToRoute('payment_display', array('orderId' => $payment->getOrderId()));
    }
}
```

Use the [testing cards](https://stripe.com/docs/testing) to test before going to production.

### Merchant's data

You need to override the template `fragments/merchantData.html.twig` in your `/templates/bundles/c975LPaymentBundle/fragments/merchantData.html.twig` and indicate there all your official data, such as address, VAT number, etc.

This template will be included in the email sent to the user after its payment.

### Mention payment system

You can mention the payment system used (i.e. in the footer) by simply include an html fragment with the following code `{% include '@c975LPayment/fragments/paymentSystem.html.twig' %}`. This will include Stripe logo and accepted cards.

### Use payment buttons/links

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

If this project **help you to reduce time to develop**, you can sponsor me via the "Sponsor" button at the top :)
