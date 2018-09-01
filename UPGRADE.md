# UPGRADE

v1.x > v2.x
-----------
When upgrading from v1.x to v2.x you should(must) do the following if they apply to your case:

- The parameters entered in `config.yml` are not used anymore as they are managed by c975L/ConfigBundle, so you can delete them.
- As the parameters are not in `config.yml`, we can't access them via `$this[->container]->getParameter()`, especially if you were using `c975_l_payment.defaultCurrency`, so you have to replace `$this->getParameter('c975_l_payment.XXX')` by `$configService->getParameter('c975LPayment.XXX')`, where `$configService` is the injection of `c975L\ConfigBundle\Service\ConfigServiceInterface`, or your can use the shortcut `$paymentService->getParameter('c975LPayment.XXX')` where `$paymentService` is the injection of `c975L\PaymentBundle\Service\PaymentServiceInterface`.
- The following parameters are now managed by c975L/ConfigBundle, so you can delete them from `parameters.yml` and `parameters.yml.dist`, but before that, copy/paste it in the config.
    - stripe_secret_key_test
    - stripe_publishable_key_test
    - stripe_secret_key_live
    - stripe_publishable_key_live
- Before the first use of parameters, you **MUST** use the console command `php bin/console config:create-first-use c975l/payment-bundle c975LPayment` to create the config files with default data. **Using this command later will reset the config to default data**