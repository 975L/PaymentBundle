<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Contract\BasketLine;
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentLinkItem;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Contract\WeighableBasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Message\GiftCardRecipientMessage;
use c975L\PaymentBundle\Message\ItemsShippedMessage;
use c975L\PaymentBundle\Provider\PaymentLinkItemProvider;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class BasketService implements BasketServiceInterface
{
    private $basket;
    private $session;
    private $user;

    // The provider this order is being charged with, resolved once per run: the checkout is opened with it and the payment row records it, so what the customer picked cannot drift from what they are sent to
    private ?PaymentGatewayInterface $gateway = null;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly PaymentFormFactoryInterface $paymentFormFactory,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $messageBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly BasketItemProviderRegistry $itemProviderRegistry,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentTestModeInterface $testMode,
        private readonly BasketCodeService $basketCodeService,
        private readonly VatCalculator $vatCalculator,
        private readonly InvoiceService $invoiceService,
        private readonly ShippingRateResolverInterface $shippingRateResolver,
    ) {
        try {
            $this->session = $this->requestStack->getSession();
        } catch (\LogicException) {
            $this->session = null;
        }
        $this->getUser();
    }

    // Creates basket
    public function create(): Basket
    {
        $basket = new Basket();
        $basket->setTotal(0);
        $basket->setQuantity(0);
        $basket->setCurrency($this->configService->get('shop-currency'));
        $basket->setShipping(0);
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setStatus('new');
        $basket->setUser($this->user);
        // Posed at creation and not at validation like the two other tokens: this one is what gets the basket back to a visitor whose session has been recycled, which happens long before they order anything
        $basket->setRecoveryToken($this->generateSecurityToken());

        $this->entityManager->persist($basket);
        $this->entityManager->flush();
        $this->session->set('basket', $basket->getId());

        return $basket;
    }

    // Deletes basket
    public function delete(): array
    {
        $identifiant = $this->session->get('basket');
        if (null !== $identifiant) {
            $this->basket = $this->get();

            $this->entityManager->remove($this->basket);
            $this->entityManager->flush();

            $this->session->remove('basket');
        }

        return [
            'total' => 0,
            'quantity' => 0,
        ];
    }

    // Returns current basket
    public function get(): ?Basket
    {
        $id = $this->session->get('basket');

        return null === $id ? null : $this->basketRepository->find($id);
    }

    // Gets total and quantity
    public function getJson(): array
    {
        $this->basket = $this->get();

        return null === $this->basket ? [] : $this->payload();
    }

    // What every answer of this service hands the basket page: the basket as it stands, and the VAT it carries, which is read back from the lines rather than kept in a column of its own (see VatCalculator)
    /**
     * @return array{basket: array<string, mixed>}
     */
    private function payload(): array
    {
        return ['basket' => $this->basket->toArray() + ['vat' => $this->vatCalculator->breakdown($this->basket)['amount']]];
    }

    // Updates total
    public function updateTotals(): void
    {
        $shippingFree = $this->configService->get('shop-shipping-free');

        // Read again before anything is counted, so what is summed below is what the catalogue says today
        $this->refreshItems();

        $items = $this->basket->getItems();

        $total = 0;
        $quantity = 0;
        $contentFlags = 0;
        // What of the basket is money bought in advance, which a promotional code is never taken off (see Basket::CONTENT_FLAG_GIFT_CARD)
        $giftCardTotal = 0;
        // What the parcel weighs, in grams - only the providers that say so contribute, the others leaving it where it stands (see WeighableBasketItemProviderInterface)
        $weight = 0;

        foreach ($items as $type => $item) {
            $provider = $this->itemProviderRegistry->get($type);

            foreach ($item as $id => $itemContent) {
                $total += $itemContent['total'];
                $quantity += $itemContent['quantity'];
                $flags = $provider->getContentFlags($itemContent);
                $contentFlags |= $flags;

                if (($flags & Basket::CONTENT_FLAG_GIFT_CARD) > 0) {
                    $giftCardTotal += $itemContent['total'];
                }

                if ($provider instanceof WeighableBasketItemProviderInterface) {
                    $weight += $provider->getWeight($itemContent) ?? 0;
                }
            }
        }

        $this->basket->setContentFlags($contentFlags);
        $this->basket->setTotal($total);

        // Shipping only for physical items, weighed against what the basket holds and not what is paid: a code must not cost the customer the free shipping they had earned
        // A threshold left unset is no threshold at all, and no longer "free from zero": read as an amount it made "$total < null" false on every basket, so a shop that had not set one was charging no delivery whatever its rate said - which the grid would have inherited, written in full and applied to nobody
        $requiresShipping = ($contentFlags & Basket::FLAG_NEEDS_SHIPPING) > 0;
        $freeFrom = (int) $shippingFree;
        $applyShipping = $requiresShipping && (0 === $freeFrom || $total < $freeFrom);
        $this->basket->setShipping($applyShipping ? $this->shipping($weight) : 0);
        $this->basket->setQuantity($quantity);

        // Read again on every change of the basket rather than kept as it was resolved: removing an article can take the basket under a code's minimum, and a card is worth what is left on it today
        $this->refreshCode($giftCardTotal);
    }

    /**
     * What the grid charges to post this parcel - nothing when it says nothing, which is what a shop that has
     * written no zone charges (see ShippingRateResolver).
     *
     * The country is the one the order carries, and it is only given at the checkout: before that the basket page
     * shows what "shop-shipping-country" says the shop posts to by default, which is an estimate and reads as one.
     * The real one is charged because validate() runs this pass again once the address is bound.
     */
    private function shipping(int $weight): int
    {
        $country = $this->basket->getCountry() ?: $this->configService->get('shop-shipping-country');

        return $this->shippingRateResolver->resolve(\is_string($country) ? $country : null, $weight) ?? 0;
    }

    /**
     * Reads the lines again off their own providers, while the basket is still a basket.
     *
     * A basket sits for days, and what it says an article costs, is called or looks like has to be what the catalogue
     * says today - the customer is charged the price they are shown, not the one that stood the evening they filled it.
     *
     * The snapshot only stops moving when validate() numbers the order: freezing a line is an event, and not the state
     * it is born in (see the frozen "discountCode" and "invoiceNumber" the same order carries).
     */
    private function refreshItems(): void
    {
        // Nothing is read again once the order is numbered: what it holds is what was sold, and what its invoice and its emails still read years later
        if ('new' !== $this->basket->getStatus()) {
            return;
        }

        $items = $this->basket->getItems();

        foreach ($items as $type => $itemsOfThisKind) {
            $provider = $this->itemProviderRegistry->get($type);

            foreach ($itemsOfThisKind as $id => $itemContent) {
                $item = $provider->findItem($id);

                // A line whose provider resolves nothing is left exactly as it stands: a payment link is minted once and never found again (see PaymentLinkItemProvider::findItem()), and saying whether an article ran out is validateCheckout()'s job at the checkout, not this pass's
                if (null === $item) {
                    continue;
                }

                // Union rather than assignment: the fresh line wins on every key it holds, and whatever a provider keeps beside the documented shape - an engraving, an option chosen at the time - survives a refresh it knows nothing about
                $items[$type][$id] = $this->line($provider, $item, $itemContent['quantity']) + $itemContent;
            }
        }

        $this->basket->setItems($items);
    }

    // The one place a line is built, so the shape it was written in is written down with it and no caller has to remember to (see BasketLine::VERSION)
    private function line(BasketItemProviderInterface $provider, object $item, int $quantity): array
    {
        return BasketLine::stamp($provider->toBasketData($item, $quantity));
    }

    /**
     * Re-resolves the code the basket is carrying, and drops it when it no longer applies.
     *
     * Silent on purpose: this runs on every add and every removal, where the customer is looking at the basket and not at a form they just submitted. The line simply disappears from the totals - BasketController::applyCode() is where a refusal is spelled out, because that is where one was asked for.
     */
    private function refreshCode(int $giftCardTotal): void
    {
        $code = $this->basket->getDiscountCode();

        if (null === $code) {
            return;
        }

        $this->basketCodeService->apply($this->basket, $this->basketCodeService->resolveForBasket($code, $this->basket, $giftCardTotal));
    }

    // What of the basket a promotional code is never taken off, for whoever needs it outside updateTotals() - the same rule read from the same providers
    public function giftCardTotal(?Basket $basket = null): int
    {
        $basket ??= $this->basket;
        $giftCardTotal = 0;

        foreach ($basket->getItems() as $type => $itemsOfThisKind) {
            $provider = $this->itemProviderRegistry->get($type);

            foreach ($itemsOfThisKind as $itemContent) {
                if (($provider->getContentFlags($itemContent) & Basket::CONTENT_FLAG_GIFT_CARD) > 0) {
                    $giftCardTotal += $itemContent['total'];
                }
            }
        }

        return $giftCardTotal;
    }

    // The code was last read when the basket was last touched, which can be days ago: one deactivated, expired or drained since then must not still be lowering what this order is charged. Dropped from the basket and refused rather than dropped silently, the customer having clicked "pay" on a total they were shown - and dropped first, so the basket they come back to says the true price instead of refusing again forever
    private function assertCodeStillHolds(): void
    {
        $code = $this->basket->getDiscountCode();
        if (null === $code) {
            return;
        }

        $resolution = $this->basketCodeService->resolveForBasket($code, $this->basket, $this->giftCardTotal());
        if ($resolution['amount'] === $this->basket->getDiscountAmount()) {
            return;
        }

        $this->basketCodeService->apply($this->basket, $resolution);
        $this->entityManager->flush();

        throw new BasketNotOrderableException($resolution['error'] ?? $this->translator->trans('error.code_changed', [], 'payment'));
    }

    // Asked first, and for the same reason as the gateway: a basket holding something that ran out, was withdrawn or was taken offline while it sat there takes no order at all, rather than being numbered and charged for what cannot be delivered. Every path of validate() is behind it, the free one included
    private function assertItemsOrderable(): void
    {
        foreach ($this->basket->getItems() as $type => $itemsOfThisKind) {
            $error = $this->itemProviderRegistry->get($type)->validateCheckout($this->basket, $itemsOfThisKind);
            if (null !== $error) {
                throw new BasketNotOrderableException($error);
            }
        }
    }

    // What updateTotals() has just written against what the customer was last shown. Flushed before it refuses, so the basket they come back to says the true price instead of refusing again forever - the same rule as assertCodeStillHolds()
    private function assertUnchanged(?int $displayed, ?int $counted, string $error): void
    {
        // Cast: a basket never counted yet carries no shipping at all, which is the same promise as a shipping of zero
        if ((int) $displayed === (int) $counted) {
            return;
        }

        $this->entityManager->flush();

        throw new BasketNotOrderableException($this->translator->trans($error, [], 'payment'));
    }

    // Each provider hands over what it will need back once the basket is delivered, which is kept on the basket rather than in the visitor's session - the webhook that confirms the payment carries no session of theirs. Asked before the free path returns: an order covered in full is delivered just like a paid one, and paid() reads the same data back
    private function collectCheckoutData(Request $request): array
    {
        $requestData = $request->request->all();
        $checkoutData = [];

        foreach ($this->basket->getItems() as $type => $itemsOfThisKind) {
            $handedOver = $this->itemProviderRegistry->get($type)->onBasketValidated($this->basket, $itemsOfThisKind, $requestData);
            if ([] !== $handedOver) {
                $checkoutData[$type] = $handedOver;
            }
        }

        return $checkoutData;
    }

    // Validates basket; $forSharing freezes the numbered order without opening a checkout, returning a link to hand to whoever settles it
    // The code is asserted first, then the items, both before anything is written: a basket refused leaves nothing half-persisted behind
    public function validate(Request $request, bool $forSharing = false): string
    {
        $this->basket = $this->get();

        $this->assertCodeStillHolds();
        $this->assertItemsOrderable();

        // What the customer was last shown, kept before the basket is counted again: the two totals below are compared to these, and not to what the fresh pass writes over them
        $displayedShipping = $this->basket->getShipping();
        $displayedDiscount = $this->basket->getDiscountAmount();

        // Counted again now that the address is bound, and this is the last pass: the coordinates form has just written the country on the basket, and until it did, the delivery was priced on the zone the shop posts to by default. What is charged has to be what the parcel actually costs to where it goes - the status turns to "validated" three lines below, after which refreshItems() lets nothing move again
        $this->updateTotals();

        // Refused rather than charged silently: the total the customer clicked "pay" on was the one estimated on the shop's default country, and no page of the site ever showed them this one
        $this->assertUnchanged($displayedShipping, $this->basket->getShipping(), 'error.shipping_changed');

        // The same for the promotional code, which updateTotals() has just resolved again: assertCodeStillHolds() reads the totals of before the refresh, so a code falling short only on the fresh ones is caught here
        $this->assertUnchanged($displayedDiscount, $this->basket->getDiscountAmount(), 'error.code_changed');

        // Asked before anything is written, and on what is left to charge: an order covered in full needs no gateway, and a shop able to charge with none leaves the basket as it was rather than half-persisted
        $this->gateway = $this->resolveGateway($request->request->getString('gateway') ?: null);
        if ($this->basket->getPayable() > 0 && null === $this->gateway) {
            throw new PaymentUnavailableException('No payment gateway holds a key');
        }

        $this->basket->setStatus('validated');
        // The one moment the customer's own request is here to be asked: everything sent afterwards is sent from somewhere else
        $this->basket->setLocale($this->requestStack->getCurrentRequest()?->getLocale());
        $this->basket->setNumber($this->generateOrderNumber());
        $this->basket->setSecurityToken($this->generateSecurityToken());
        // The mode the checkout is being opened in, kept on the order: the toggle can be flipped back before this one is paid, and it is what tells a rehearsal from a sale afterwards
        $this->basket->setTestMode($this->testMode->isEnabled());
        $this->entityManager->persist($this->basket);

        // Written now, and flushed by whichever path this method leaves through
        $this->basket->setCheckoutData($this->collectCheckoutData($request));

        // Nothing left to pay - the free path, which a code covering the whole order now takes too
        if (0 === $this->basket->getPayable()) {
            $url = $this->urlGenerator->generate(
                'basket_paid',
                [
                    'number' => $this->basket->getNumber(),
                    'securityToken' => $this->basket->getSecurityToken(),
                ],
                $this->urlGenerator::ABSOLUTE_URL
            );
            $this->entityManager->flush();

            return $url;
        }

        // An order somebody else is going to settle opens no checkout here: the payer opens their own when they follow the link (see payShared())
        $checkout = $forSharing ? null : $this->createCheckout();
        if (null !== $checkout) {
            $this->createPayment($checkout->reference);
        }

        if ($forSharing) {
            $this->basket->setShareToken($this->generateSecurityToken());
        }

        $this->entityManager->flush();

        if ($forSharing) {
            // The order stops being the customer's basket: they go on browsing with a new one, and what they shared can no longer be edited under the payer's feet (see reopen(), which only ever touches the basket the session names)
            $this->session?->remove('basket');

            return $this->urlGenerator->generate(
                'basket_shared',
                [
                    'number' => $this->basket->getNumber(),
                    'securityToken' => $this->basket->getSecurityToken(),
                ],
                $this->urlGenerator::ABSOLUTE_URL
            );
        }

        // Redirects to payment
        return $checkout->url;
    }

    /**
     * Opens the checkout of an order somebody else is settling, and hands back the page to send them to.
     *
     * The success url is the payer's own and not the customer's: the one the customer gets back shows the delivery page, i.e. the name and the address the order is going to, which is the one thing a gift must not disclose.
     */
    public function payShared(Basket $basket): string
    {
        // Only an order still waiting for its money: one already settled, or one the customer took back to a basket, has nothing to charge
        if ('validated' !== $basket->getStatus() || null === $basket->getShareToken() || $basket->getPayable() <= 0) {
            throw new PaymentUnavailableException('This order is not waiting for a payment');
        }

        // The provider the order was opened with, which is the one the customer chose; the payer is given no choice of their own, and falls back on the shop's default when that provider no longer charges
        $this->gateway = $this->resolveGateway($basket->getPayment()?->getGateway());
        if (null === $this->gateway) {
            throw new PaymentUnavailableException('No payment gateway holds a key');
        }

        $this->basket = $basket;

        // A link followed twice opens a second checkout, and the first is called off rather than left payable: two sessions on one order is two charges for it
        $this->expireCheckout($basket);

        $checkout = $this->createCheckout($this->urlGenerator->generate(
            'basket_shared_paid',
            [
                'number' => $basket->getNumber(),
                'shareToken' => $basket->getShareToken(),
            ],
            $this->urlGenerator::ABSOLUTE_URL
        ));
        $this->createPayment($checkout->reference);

        $this->entityManager->flush();

        return $checkout->url;
    }

    /**
     * Writes the order a payment link stands for, and hands back the address to send whoever is going to settle it.
     *
     * The same frozen order payShared() charges, with the shop typing the line in place of a catalogue: nothing of
     * the checkout, the webhook, the payment row or the back-office is duplicated for it. It is written here rather
     * than in a service of its own because the numbering, the tokens and what is flushed when all live in this
     * class - three internals a second service would have to be handed.
     */
    public function createPaymentLink(string $label, int $amount, string $email, ?string $description = null): string
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A payment link is worth more than nothing');
        }

        // Asked before anything is written, as validate() asks it: a link nobody can open a checkout from is a link nobody can settle. Nobody is standing in front of a basket page to pick a provider here, so the shop's default is what the payer will be sent to
        $this->gateway = $this->resolveGateway(null);
        if (null === $this->gateway) {
            throw new PaymentUnavailableException('No payment gateway holds a key');
        }

        // Built by the provider and not here, so the payer's page, the checkout and the confirmation email all read the shape they read for every other kind
        $line = $this->line(
            $this->itemProviderRegistry->get(PaymentLinkItemProvider::KIND),
            new PaymentLinkItem($label, $amount, $description),
            1
        );

        $basket = new Basket()
            ->setItems([PaymentLinkItemProvider::KIND => [PaymentLinkItem::ID => $line]])
            ->setTotal($amount)
            ->setQuantity(1)
            // A service is carried by nothing, so no delivery is charged for it whatever the shop's own shipping costs
            ->setShipping(0)
            ->setCurrency((string) $this->configService->get('shop-currency'))
            ->setContentFlags(Basket::CONTENT_FLAG_SERVICE)
            // Already validated: this order is never a basket somebody browses with, and no session names it
            ->setStatus('validated')
            ->setNumber($this->generateOrderNumber())
            ->setSecurityToken($this->generateSecurityToken())
            ->setShareToken($this->generateSecurityToken())
            // Written frozen, so stamped here rather than at a checkout it never passes through
            ->setTestMode($this->testMode->isEnabled())
            // Required, unlike on an order taken at the counter: the confirmation e-mail is what tells the customer their payment went through, and its blind copy is what tells the shop
            ->setEmail($email)
            // Stamped from the back-office request, the e-mails that follow being sent from a webhook that knows no language
            ->setLocale($this->requestStack->getCurrentRequest()?->getLocale())
            ->setCreation(new \DateTime())
            ->setModification(new \DateTime())
        ;

        // Attached to nobody: the admin writing the link is not the customer buying it, and an order filed under their account would show up in their own order history
        $this->entityManager->persist($basket);
        $this->entityManager->flush();

        // The short address and not the long one: a payment link is sent by text message as often as by e-mail, and the number in the long address names nothing the token does not (see BasketController::shortPay())
        return $this->urlGenerator->generate(
            'basket_short_pay',
            ['shareToken' => $basket->getShareToken()],
            $this->urlGenerator::ABSOLUTE_URL
        );
    }

    // Delivers a basket whose payment is settled - stock, tickets, downloads and the confirmation email. Reached from the provider's webhook and from the customer's own return, never from a url alone
    public function paid(Basket $basket): void
    {
        if ('validated' !== $basket->getStatus()) {
            return;
        }

        // Nothing is delivered on the strength of the url the customer came back on: that url is handed to them before they pay. A basket with something to pay is only delivered once a provider's confirmation has recorded its payment as finished - a basket with nothing to pay has no payment at all, and is the one case that goes through on its own
        if ($basket->getPayable() > 0 && true !== $basket->getPayment()?->isFinished()) {
            return;
        }

        // The webhook and the customer's return confirm the same payment within the same second, and both would read "validated" and deliver. Only the request the database hands the basket to goes on to decrement stock and send the email
        if (!$this->basketRepository->claimPaid($basket)) {
            return;
        }

        // The claim went round the unit of work, which still holds the basket as it was read
        $basket->setStatus('paid');
        $basket->setModification(new \DateTime());

        // The code is spent here and nowhere else, an abandoned basket burning neither quota nor balance; a refusal means it ran out since the last check, and the order being paid nothing is undone but the log
        if (!$this->basketCodeService->redeem($basket)) {
            $this->logger->error('Basket {number}: the code {code} could not be redeemed for {amount}, the order was charged all the same', [
                'number' => $basket->getNumber(),
                'code' => $basket->getDiscountCode(),
                'amount' => $basket->getDiscountAmount(),
            ]);
        }

        // Lets each item kind's provider apply its own paid effects (ordered quantity, contributor, tickets...), handing back what it left at validation
        $checkoutData = $basket->getCheckoutData();
        foreach ($basket->getItems() as $type => $itemsOfThisKind) {
            $this->itemProviderRegistry->get($type)->onBasketPaid($basket, $itemsOfThisKind, $checkoutData[$type] ?? []);
        }

        // A delivered basket is no longer a checkout: what was carried across it has been used, and some of it is the customer's own details
        $basket->setCheckoutData([]);

        $this->entityManager->flush();

        // The invoice number, drawn here and nowhere else: this runs once per order, the database having said so above, which is what keeps the sequence free of gaps and free of repeats
        $this->invoiceService->assign($basket);

        // Dispatch messages emails
        $this->sendEmails($basket);
    }

    // The customer's own return to the site, which asks the provider whether that payment went through rather than trusting the url it arrived on. The webhook confirms the same payment on its own, so this is a shortcut, never the only path
    public function confirmReturn(Basket $basket, Request $request): void
    {
        if ('validated' === $basket->getStatus()) {
            // A basket with nothing to pay has no provider to ask
            if (0 === $basket->getPayable()) {
                $this->paid($basket);
            } else {
                $this->confirmWithGateway($basket, $request);
            }
        }

        // Whichever path numbered it, an order is no longer the visitor's current basket - but only when the session names this one: on a shared order the visitor here is the payer, and their own basket is none of this order's business
        if (in_array($basket->getStatus(), ['paid', 'shipped'], true) && $this->session?->get('basket') === $basket->getId()) {
            $this->session->remove('basket');
        }
    }

    // Asks the provider that took the money to read the return, when it is able to - which the shop's default is no longer once the customer picks, and never was for an order settled before the shop changed provider
    private function confirmWithGateway(Basket $basket, Request $request): void
    {
        $slug = (string) $basket->getPayment()?->getGateway();
        $gateway = $this->gatewayRegistry->has($slug) ? $this->gatewayRegistry->get($slug) : null;
        if (!$gateway instanceof ReturnAwareGatewayInterface) {
            return;
        }

        // Reaching the provider can fail, and its webhook confirms the same payment on its own: the customer is shown their order as it stands rather than an error page
        try {
            $notification = $gateway->readReturn($request, $basket->getPayment()?->getGatewayReference());
        } catch (\Exception $e) {
            $this->logger->error('Payment return could not be confirmed', ['basket' => $basket->getId(), 'error' => $e->getMessage()]);

            return;
        }

        // The url names one basket and the provider answers about another: nothing is confirmed on somebody else's payment
        if (null !== $notification && $notification->basketId === (string) $basket->getId()) {
            $this->applyNotification($notification);
        }
    }

    // Sends emails after payment
    public function sendEmails(Basket $basket)
    {
        // Confirm order, when the order names somebody to confirm it to. An order taken from the front always does, CoordinatesType asking for the address - one written in the back-office may not, and EmailService falling back on the site's own address would send the customer's confirmation to the shop, or fail outright on a site having none and leave Messenger retrying for ever
        if (null !== $basket->getEmail() && '' !== $basket->getEmail()) {
            $this->messageBus->dispatch(new ConfirmOrderMessage($basket->getId()));
        }

        // Tell whoever the cards were bought for, when the buyer gave an address to write to. Dispatched apart so a bounced recipient address never costs the buyer their own confirmation
        if ($basket->hasGiftCardRecipient()) {
            $this->messageBus->dispatch(new GiftCardRecipientMessage($basket->getId()));
        }
    }

    // Sends email when physical items/counterparts are shipped
    public function itemsShipped(string $number, string $type): Basket
    {
        $basket = $this->basketRepository->findOneBy(['number' => $number]);

        if (null === $basket) {
            throw new \Exception('Basket not found');
        }
        if ('shipped' !== $basket->getStatus()) {
            $items = $basket->getItems();

            // Items
            if ('product' === $type && isset($items['product'])) {
                $basket->setItemsShipped(new \DateTime());
            }

            // Counterparts
            if ('crowdfunding' === $type && isset($items['crowdfunding'])) {
                $basket->setCounterpartsShipped(new \DateTime());
            }

            // Check if there's items and counterparts and if both have been shipped
            if (1 === count($items) or (null !== $basket->getItemsShipped() and null !== $basket->getCounterpartsShipped())) {
                $basket->setStatus('shipped');
            }
            $basket->setModification(new \DateTime());

            $this->entityManager->persist($basket);
            $this->entityManager->flush();

            // Sends email
            $this->messageBus->dispatch(new ItemsShippedMessage($basket->getId(), $type));
        }

        return $basket;
    }

    // What the call asks to be added, refused whole rather than half-read
    // Read before anything is created: what the body carries used to be read after the basket, so a call carrying nothing crashed on the read and left that empty basket behind
    /** @return array{0: mixed, 1: int, 2: string} */
    private function readAddition(Request $request): array
    {
        $data = $this->readPayload($request);
        if (!isset($data['id'], $data['quantity'], $data['type']) || !is_numeric($data['quantity']) || !\is_string($data['type']) || !$this->itemProviderRegistry->has($data['type'])) {
            throw new BadRequestHttpException('The body must carry "id", "quantity" and a "type" a provider answers for.');
        }

        return [$data['id'], (int) $data['quantity'], $data['type']];
    }

    // An order is no longer a basket: one still named by the session - its customer paid and never came back to the site, so nothing cleared it - is left alone and the visitor starts a new one
    private function basketToAddTo(): Basket
    {
        $basket = $this->get();

        return null !== $basket && !$this->isOrder($basket) ? $basket : $this->create();
    }

    // The line written over, dropped, or opened, on the basket's own lines - the caller writing them back once the totals have been drawn
    private function addToItems(array &$items, string $type, mixed $itemId, object $item, BasketItemProviderInterface $provider, int $quantity): void
    {
        // New item
        if (!isset($items[$type][$itemId])) {
            $items[$type][$item->getId()] = $this->line($provider, $item, $quantity);

            return;
        }

        // Deletes item if quantity is 0
        if ($items[$type][$itemId]['quantity'] + $quantity <= 0) {
            unset($items[$type][$itemId]);

            return;
        }

        // Otherwise updates quantity unless it's a digital item
        // Only the quantity is written here: the line's price, its totals and its VAT are drawn from the item itself by the refresh updateTotals() runs afterwards
        if (false === method_exists($item, 'getFile') || null === $item->getFile()->getName()) {
            $items[$type][$itemId]['quantity'] += $quantity;
        }
    }

    // Adds the item the call names to the basket, and hands back the basket as the page redraws it
    public function addItem(Request $request): array
    {
        [$itemId, $quantity, $type] = $this->readAddition($request);

        $this->basket = $this->basketToAddTo();
        $this->reopen($this->basket);

        $items = $this->basket->getItems();

        $provider = $this->itemProviderRegistry->get($type);
        $item = $provider->findItem($itemId);

        if (null === $item) {
            throw new \Exception('Item not found');
        }

        $error = $provider->validateAddition($item, $quantity);
        if (null !== $error) {
            return ['error' => $error];
        }

        $this->addToItems($items, $type, $itemId, $item, $provider, $quantity);

        $this->basket->setItems($items);
        $this->basket->setModification(new \DateTime());

        $this->updateTotals();
        $this->entityManager->persist($this->basket);
        $this->entityManager->flush();

        return $this->payload();
    }

    /**
     * Takes the one code the basket page asks for - a promotional code or a gift card, the customer holding a code and not a category.
     *
     * The refusal is spelled out here and nowhere else: this is the one place a customer typed something and is waiting to be told what became of it (see BasketService::refreshCode() for the silent path).
     *
     * @return array{basket?: array<string, mixed>, error?: string}
     */
    public function applyCode(Request $request): array
    {
        $this->basket = $this->get();

        // Nothing is added to an order, nor to a basket the session no longer names
        if (null === $this->basket || $this->isOrder($this->basket)) {
            return $this->getJson();
        }

        $this->reopen($this->basket);

        $data = $this->readPayload($request);
        $code = isset($data['code']) && \is_string($data['code']) ? $data['code'] : '';

        $resolution = $this->basketCodeService->resolveForBasket($code, $this->basket, $this->giftCardTotal());

        if (null !== $resolution['error']) {
            return ['error' => $resolution['error']];
        }

        $this->basketCodeService->apply($this->basket, $resolution);
        $this->basket->setModification(new \DateTime());

        $this->entityManager->persist($this->basket);
        $this->entityManager->flush();

        return $this->payload();
    }

    // Takes the code back off, which is the same click again on a line the customer is looking at
    public function removeCode(): array
    {
        $this->basket = $this->get();

        if (null === $this->basket || $this->isOrder($this->basket)) {
            return $this->getJson();
        }

        $this->reopen($this->basket);
        $this->basketCodeService->clear($this->basket);
        $this->basket->setModification(new \DateTime());

        $this->entityManager->persist($this->basket);
        $this->entityManager->flush();

        return $this->payload();
    }

    // Deletes item from basket
    public function deleteItem(Request $request): array
    {
        $this->basket = $this->get();

        // Nothing is taken out of an order, nor out of a basket the session no longer names
        if (null === $this->basket || $this->isOrder($this->basket)) {
            return $this->getJson();
        }

        $this->reopen($this->basket);

        $data = $this->readPayload($request);
        if (!isset($data['id'], $data['type']) || !\is_string($data['type'])) {
            throw new BadRequestHttpException('The body must carry "id" and "type".');
        }

        $type = $data['type'];

        // Deletes item from basket
        $items = $this->basket->getItems();
        if (isset($items[$type][$data['id']])) {
            unset($items[$type][$data['id']]);
        }

        $this->basket->setItems($items);
        $this->basket->setModification(new \DateTime());

        $this->updateTotals();
        $this->entityManager->persist($this->basket);
        $this->entityManager->flush();

        return $this->getJson();
    }

    // The JSON body a basket call carries, an unreadable one being the caller's error and answered as such rather than as a crash of the site
    private function readPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            throw new BadRequestHttpException('The request body is not valid JSON.');
        }
    }

    // A basket that has been paid for, or shipped, and is an order rather than something still being filled
    private function isOrder(Basket $basket): bool
    {
        return in_array($basket->getStatus(), ['paid', 'shipped'], true);
    }

    // A basket edited after it was validated no longer matches the checkout the customer is looking at, so it goes back to being a basket. The checkout left open at the provider can then deliver nothing - paid() only ever delivers a "validated" basket - and validate() numbers it again, with a new order number and a new security token that make the old return url useless
    private function reopen(Basket $basket): void
    {
        if ('validated' !== $basket->getStatus()) {
            return;
        }

        $basket->setStatus('new');
        $basket->setCheckoutData([]);
        $this->expireCheckout($basket);
    }

    // Calls off the checkout this basket had already opened, so the customer who still has it in a tab cannot pay for a basket that no longer exists. Refusing that payment afterwards is all the site could otherwise do, and by then they are charged and take delivery of nothing
    private function expireCheckout(Basket $basket): void
    {
        $payment = $basket->getPayment();
        $reference = $payment?->getGatewayReference();

        // A payment already settled is no longer a checkout to call off
        if (null === $payment || null === $reference || true === $payment->isFinished()) {
            return;
        }

        // Asked of the provider that opened it, which the active one may no longer be
        $slug = (string) $payment->getGateway();
        $gateway = $this->gatewayRegistry->has($slug) ? $this->gatewayRegistry->get($slug) : null;

        if ($gateway instanceof ExpirableGatewayInterface) {
            // A checkout Stripe has already expired, or cannot be reached about, answers with an exception: the basket is still reopened, and the payment that may yet arrive for it is refused on its amount
            try {
                $gateway->expireCheckout($reference);
            } catch (\Exception $e) {
                $this->logger->error('Checkout could not be expired', ['basket' => $basket->getId(), 'reference' => $reference, 'error' => $e->getMessage()]);
            }
        }

        $payment->setGatewayReference(null);
    }

    // Generates order number with format AAAAMM-YY-XXXXX
    private function generateOrderNumber(): string
    {
        // Generates a prefix on two random upper letters
        $datePart = date('Ym');
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $prefix = $letters[random_int(0, strlen($letters) - 1)] . $letters[random_int(0, strlen($letters) - 1)];

        // Random number
        $randomBytes = random_bytes(4);
        $randomPart = strtoupper(bin2hex($randomBytes));
        $randomPart = substr($randomPart, 0, 5);

        // Test part
        $testPart = $this->testMode->isEnabled() ? 'TEST-' : '';

        return $testPart . $datePart . '-' . $prefix . '-' . $randomPart;
    }

    // Generates security token
    public function generateSecurityToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    // Creates form
    public function createForm(string $name, Basket $basket): FormInterface
    {
        return $this->paymentFormFactory->create($name, $basket);
    }

    // Creates payment
    public function createPayment(?string $gatewayReference = null): void
    {
        // A checkout abandoned and started again re-prices the same basket: its payment row is written over rather than replaced, Basket holding exactly one Payment and a second only orphaning the first
        $payment = $this->basket->getPayment() ?? new Payment()->setCreation(new \DateTime());

        $payment->setBasket($this->basket);
        $payment->setFinished(false);
        // What the gateway is asked for, discount deducted: this row is the record of the transaction, and the back-office reads it against the provider's own dashboard
        $payment->setAmount($this->basket->getPayable());
        $payment->setCurrency($this->basket->getCurrency());
        $payment->setGateway(($this->gateway ?? $this->gatewayRegistry->getActive())->getSlug());
        $payment->setGatewayReference($gatewayReference);
        $payment->setModification(new \DateTime());
        $payment->setUser($this->user);

        $this->entityManager->persist($payment);
    }

    // Opens a checkout with the provider this order is charged with: the url to send the customer to, and what that provider calls the checkout by
    public function createCheckout(?string $successUrl = null): CheckoutSession
    {
        return ($this->gateway ?? $this->gatewayRegistry->getActive())->createCheckout($this->buildCheckoutRequest($successUrl));
    }

    /**
     * The provider an order is charged with, from what was asked for.
     *
     * Never the slug as it arrived: it comes off a form, and a provider named there but not offered - one whose
     * keys were cleared since the page was drawn, or one simply typed in - would open a checkout the shop cannot
     * be paid through. Anything unoffered falls back on the shop's default, and on the first provider that does
     * hold keys when even the default holds none.
     */
    private function resolveGateway(?string $asked): ?PaymentGatewayInterface
    {
        $offered = $this->gatewayRegistry->getOffered();

        if (null !== $asked && isset($offered[$asked])) {
            return $offered[$asked];
        }

        return reset($offered) ?: null;
    }

    // The basket priced in terms no provider owns, one line per item plus the shipping; $successUrl is where the payer lands, the customer's order page unless somebody else settles it (see payShared())
    private function buildCheckoutRequest(?string $successUrl = null): CheckoutRequest
    {
        $lines = [];
        foreach ($this->basket->getItems() as $type => $items) {
            foreach ($items as $id => $item) {
                // "Parent (item)" is how a catalogue entry names its line; an item hanging under none - a payment link - is named by its own label alone rather than by an empty pair of brackets
                $parentTitle = (string) ($item['parent']['title'] ?? '');
                $lines[] = [
                    'name' => '' === $parentTitle ? $item['item']['title'] : $parentTitle . ' (' . $item['item']['title'] . ')',
                    'amount' => $item['item']['price'],
                    'quantity' => $item['quantity'],
                ];
            }
        }

        if ($this->basket->getShipping() > 0) {
            $lines[] = [
                'name' => $this->translator->trans('label.shipping', [], 'payment'),
                'amount' => $this->basket->getShipping(),
                'quantity' => 1,
            ];
        }

        // A code took something off and no gateway accepts a negative line, so the order is charged as one line at what is due, the itemised version staying the site's own
        if ($this->basket->getDiscountAmount() > 0) {
            $lines = [[
                'name' => $this->translator->trans('label.order_number', [], 'payment') . ' ' . $this->basket->getNumber(),
                'amount' => $this->basket->getPayable(),
                'quantity' => 1,
            ]];
        }

        return new CheckoutRequest(
            $this->basket->getCurrency(),
            $lines,
            $successUrl ?? $this->urlGenerator->generate(
                'basket_paid',
                [
                    'number' => $this->basket->getNumber(),
                    'securityToken' => $this->basket->getSecurityToken(),
                ],
                $this->urlGenerator::ABSOLUTE_URL
            ),
            $this->urlGenerator->generate('basket_validate', [], $this->urlGenerator::ABSOLUTE_URL),
            $this->basket->getEmail(),
            [
                'basket_id' => (string) $this->basket->getId(),
                'order_number' => (string) $this->basket->getNumber(),
            ],
        );
    }

    // Records a payment the provider confirms and delivers the basket it settles, whether the provider said so through its webhook or when asked on the customer's return
    public function applyNotification(PaymentNotification $notification): void
    {
        $basket = $this->basketRepository->find($notification->basketId);
        if (null === $basket) {
            // Answering an error would have the provider replay this notification for days over a basket that is not coming back
            $this->logger->error('Payment notification names an unknown basket', ['basket' => $notification->basketId, 'gateway' => $notification->gateway]);

            return;
        }

        // What the provider charged, against what the basket holds *now* rather than against the payment row: both were written when the basket was validated, and comparing them would agree with itself. A basket can still be added to once it is validated, and the checkout the customer paid was priced before that - so a basket grown since is refused in both directions, the charge staying for an admin to settle rather than delivering contents nobody paid for. What is due is what was payable - the checkout is opened for the discounted amount (see buildCheckoutRequest()), so comparing against the undiscounted total refuses every order settled with a code or a gift card
        $due = $basket->getPayable();
        if (null !== $notification->amount && $notification->amount !== $due) {
            $this->logger->error('Payment notification amount does not match the basket', [
                'basket' => $notification->basketId,
                'gateway' => $notification->gateway,
                'charged' => $notification->amount,
                'due' => $due,
            ]);

            return;
        }

        $payment = $basket->getPayment();

        if ($payment) {
            $payment->setGateway($notification->gateway);
            $payment->setTransactionId($notification->transactionId);
            $payment->setPaymentMethod($notification->paymentMethod);
            $payment->setFinished(true);
            $payment->setGatewayReference(null);
            $payment->setModification(new \DateTime());

            $this->entityManager->persist($payment);
        }

        $this->entityManager->persist($basket);
        $this->entityManager->flush();

        // The payment is proven, so the basket is delivered now rather than waiting for the customer to come back
        $this->paid($basket);
    }

    // Gets user
    private function getUser(): void
    {
        $token = $this->tokenStorage->getToken();
        $this->user = null !== $token ? $token->getUser() : null;
    }
}
