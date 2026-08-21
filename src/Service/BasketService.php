<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 */

namespace c975L\PaymentBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\CheckoutRequest;
use c975L\PaymentBundle\Contract\CheckoutSession;
use c975L\PaymentBundle\Contract\ExpirableGatewayInterface;
use c975L\PaymentBundle\Contract\PaymentNotification;
use c975L\PaymentBundle\Contract\ReturnAwareGatewayInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Entity\Payment;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Form\PaymentFormFactoryInterface;
use c975L\PaymentBundle\Message\ConfirmOrderMessage;
use c975L\PaymentBundle\Message\ItemsShippedMessage;
use c975L\PaymentBundle\Registry\BasketItemProviderRegistry;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class BasketService implements BasketServiceInterface
{
    private $basket;
    private $session;
    private $user;

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
        $basket->setShipping($this->configService->get('shop-shipping'));
        $basket->setCreation(new \DateTime());
        $basket->setModification(new \DateTime());
        $basket->setStatus('new');
        $basket->setUser($this->user);

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

        return null === $this->basket ? [] : ['basket' => $this->basket->toArray()];
    }

    // Updates total
    public function updateTotals(): void
    {
        $shipping = $this->configService->get('shop-shipping');
        $shippingFree = $this->configService->get('shop-shipping-free');

        $items = $this->basket->getItems();

        $total = 0;
        $quantity = 0;
        $contentFlags = 0;

        foreach ($items as $type => $item) {
            $provider = $this->itemProviderRegistry->get($type);

            foreach ($item as $id => $itemContent) {
                $total += $itemContent['total'];
                $quantity += $itemContent['quantity'];
                $contentFlags |= $provider->getContentFlags($itemContent);
            }
        }

        $this->basket->setContentFlags($contentFlags);
        $this->basket->setTotal($total);

        // Shipping only for physical items
        $requiresShipping = ($contentFlags & Basket::FLAG_NEEDS_SHIPPING) > 0;
        $applyShipping = $requiresShipping && $total < $shippingFree;
        $this->basket->setShipping($applyShipping ? $shipping : 0);
        $this->basket->setQuantity($quantity);
    }

    // Validates basket
    public function validate(Request $request): string
    {
        $this->basket = $this->get();

        // Asked first, and for the same reason as the gateway below: a basket holding something that ran out, was withdrawn or was taken offline while it sat there takes no order at all, rather than being numbered and charged for what cannot be delivered. Every path of this method is behind it, the free one included
        foreach ($this->basket->getItems() as $type => $itemsOfThisKind) {
            $error = $this->itemProviderRegistry->get($type)->validateCheckout($this->basket, $itemsOfThisKind);
            if (null !== $error) {
                throw new BasketNotOrderableException($error);
            }
        }

        // Asked before anything is written: a basket to charge with no gateway able to do it is left as it was, where the provider's exception used to fire once the order was numbered and half-persisted
        $gateway = $this->gatewayRegistry->getActiveOrNull();
        if ($this->basket->getTotal() > 0 && (null === $gateway || !$gateway->isConfigured())) {
            throw new PaymentUnavailableException('The active payment gateway holds no key');
        }

        $this->basket->setStatus('validated');
        $this->basket->setNumber($this->generateOrderNumber());
        $this->basket->setSecurityToken($this->generateSecurityToken());
        $this->entityManager->persist($this->basket);

        // If total = 0
        if (0 === $this->basket->getTotal()) {
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

        // Creates payment
        $checkout = $this->createCheckout();
        $this->createPayment($checkout->reference);

        $this->entityManager->flush();

        // Each provider hands over what it will need back once the basket is delivered, which is kept on the basket rather than in the visitor's session - the webhook that confirms the payment carries no session of theirs
        $requestData = $request->request->all();
        $checkoutData = [];
        foreach ($this->basket->getItems() as $type => $itemsOfThisKind) {
            $handedOver = $this->itemProviderRegistry->get($type)->onBasketValidated($this->basket, $itemsOfThisKind, $requestData);
            if ([] !== $handedOver) {
                $checkoutData[$type] = $handedOver;
            }
        }

        // Written now: nothing else flushes after this loop, and what is left in the unit of work is exactly what the webhook would come looking for
        $this->basket->setCheckoutData($checkoutData);
        $this->entityManager->flush();

        // Redirects to payment
        return $checkout->url;
    }

    // Delivers a basket whose payment is settled - stock, tickets, downloads and the confirmation email. Reached from the provider's webhook and from the customer's own return, never from a url alone
    public function paid(Basket $basket): void
    {
        if ('validated' !== $basket->getStatus()) {
            return;
        }

        // Nothing is delivered on the strength of the url the customer came back on: that url is handed to them before they pay. A basket with something to pay is only delivered once a provider's confirmation has recorded its payment as finished - a basket with nothing to pay has no payment at all, and is the one case that goes through on its own
        if ($basket->getTotal() > 0 && true !== $basket->getPayment()?->isFinished()) {
            return;
        }

        // The webhook and the customer's return confirm the same payment within the same second, and both would read "validated" and deliver. Only the request the database hands the basket to goes on to decrement stock and send the email
        if (!$this->basketRepository->claimPaid($basket)) {
            return;
        }

        // The claim went round the unit of work, which still holds the basket as it was read
        $basket->setStatus('paid');
        $basket->setModification(new \DateTime());

        // Lets each item kind's provider apply its own paid effects (ordered quantity, contributor, tickets...), handing back what it left at validation
        $checkoutData = $basket->getCheckoutData();
        foreach ($basket->getItems() as $type => $itemsOfThisKind) {
            $this->itemProviderRegistry->get($type)->onBasketPaid($basket, $itemsOfThisKind, $checkoutData[$type] ?? []);
        }

        // A delivered basket is no longer a checkout: what was carried across it has been used, and some of it is the customer's own details
        $basket->setCheckoutData([]);

        $this->entityManager->flush();

        // Dispatch messages emails
        $this->sendEmails($basket);
    }

    // The customer's own return to the site, which asks the provider whether that payment went through rather than trusting the url it arrived on. The webhook confirms the same payment on its own, so this is a shortcut, never the only path
    public function confirmReturn(Basket $basket, Request $request): void
    {
        if ('validated' === $basket->getStatus()) {
            // A basket with nothing to pay has no provider to ask
            if (0 === $basket->getTotal()) {
                $this->paid($basket);
            } else {
                $this->confirmWithGateway($basket, $request);
            }
        }

        // Whichever path numbered it, an order is no longer the visitor's current basket
        if (in_array($basket->getStatus(), ['paid', 'shipped'], true)) {
            $this->session?->remove('basket');
        }
    }

    // Asks the active provider to read the return, when it is able to
    private function confirmWithGateway(Basket $basket, Request $request): void
    {
        $gateway = $this->gatewayRegistry->getActiveOrNull();
        if (!$gateway instanceof ReturnAwareGatewayInterface) {
            return;
        }

        // Reaching the provider can fail, and its webhook confirms the same payment on its own: the customer is shown their order as it stands rather than an error page
        try {
            $notification = $gateway->readReturn($request);
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
        // Confirm order
        $this->messageBus->dispatch(new ConfirmOrderMessage($basket->getId()));
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

    // Adds item to basket and returns total and quantity
    public function addItem(Request $request): array
    {
        $basket = $this->get();

        // An order is no longer a basket: one still named by the session - its customer paid and never came back to the site, so nothing cleared it - is left alone and the visitor starts a new one
        if (null !== $basket && $this->isOrder($basket)) {
            $basket = null;
        }

        $this->basket = $basket ?? $this->create();
        $this->reopen($this->basket);

        $data = $request->toArray();
        $items = $this->basket->getItems();
        $itemId = $data['id'];
        $quantity = $data['quantity'];
        $type = $data['type'];

        $provider = $this->itemProviderRegistry->get($type);
        $item = $provider->findItem($itemId);

        if (null === $item) {
            throw new \Exception('Item not found');
        }

        $error = $provider->validateAddition($item, $quantity);
        if (null !== $error) {
            return ['error' => $error];
        }

        // Adds item to basket
        if (isset($items[$type][$itemId])) {
            // Deletes item if quantity is 0
            if ($items[$type][$itemId]['quantity'] + $quantity <= 0) {
                unset($items[$type][$itemId]);
            // Otherwise updates quantity unless it's a digital item
            } elseif (false === method_exists($item, 'getFile') || null === $item->getFile()->getName()) {
                if (method_exists($item, 'getVat')) {
                    $items[$type][$itemId]['totalVat'] = $items[$type][$itemId]['quantity'] * $item->getVat();
                }
                $items[$type][$itemId]['quantity'] += $quantity;
                $items[$type][$itemId]['total'] = $items[$type][$itemId]['quantity'] * $item->getPrice();
            }
        // New item
        } else {
            $items[$type][$item->getId()] = $provider->toBasketData($item, $quantity);
        }

        $this->basket->setItems($items);
        $this->basket->setModification(new \DateTime());

        $this->updateTotals();
        $this->entityManager->persist($this->basket);
        $this->entityManager->flush();

        return [
            'basket' => $this->basket->toArray(),
        ];
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

        $data = $request->toArray();
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
        $payment->setAmount($this->basket->getTotal() + $this->basket->getShipping());
        $payment->setCurrency($this->basket->getCurrency());
        $payment->setGateway($this->gatewayRegistry->getActive()->getSlug());
        $payment->setGatewayReference($gatewayReference);
        $payment->setModification(new \DateTime());
        $payment->setUser($this->user);

        $this->entityManager->persist($payment);
    }

    // Opens a checkout with the active provider: the url to send the customer to, and what that provider calls the checkout by
    public function createCheckout(): CheckoutSession
    {
        return $this->gatewayRegistry->getActive()->createCheckout($this->buildCheckoutRequest());
    }

    // The basket priced in terms no provider owns - one line per item, plus one for the shipping when there is any
    private function buildCheckoutRequest(): CheckoutRequest
    {
        $lines = [];
        foreach ($this->basket->getItems() as $type => $items) {
            foreach ($items as $id => $item) {
                $lines[] = [
                    'name' => $item['parent']['title'] . ' (' . $item['item']['title'] . ')',
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

        return new CheckoutRequest(
            $this->basket->getCurrency(),
            $lines,
            $this->urlGenerator->generate(
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

        // What the provider charged, against what the basket holds *now* rather than against the payment row: both were written when the basket was validated, and comparing them would agree with itself. A basket can still be added to once it is validated, and the checkout the customer paid was priced before that - so a basket grown since is refused in both directions, the charge staying for an admin to settle rather than delivering contents nobody paid for
        $due = $basket->getTotal() + $basket->getShipping();
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

    // Deletes unvalidated baskets
    public function deleteUnvalidated(): void
    {
        $count = 0;
        $batchSize = 20;

        $baskets = $this->basketRepository->findUnvalidated(14);
        foreach ($baskets as $basket) {
            $this->entityManager->remove($basket);
            ++$count;

            // Flush every $batchSize to avoid memory issues
            if (0 === $count % $batchSize) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        if (0 !== $count % $batchSize) {
            $this->entityManager->flush();
        }
    }

    // Gets user
    private function getUser(): void
    {
        $token = $this->tokenStorage->getToken();
        $this->user = null !== $token ? $token->getUser() : null;
    }
}
