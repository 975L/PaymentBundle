<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\ConfigBundle\Service\LocalizedRouteNegotiator;
use c975L\ConfigBundle\Service\SiteLocales;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The buyer's own order history - what the emailed link never was: it expires, and it is the only trace a purchase left until now
// Only baskets carrying a user are listed. Matching on the email address instead would hand someone the orders of whoever used that address before them, the moment they register an account with it
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class CustomerAreaController extends AbstractController
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BasketDownloadRegistry $basketDownloadRegistry,
        private readonly LocalizedRouteNegotiator $negotiator,
        private readonly SiteLocales $siteLocales,
    ) {
    }

    // Every language the site declares, with nothing to gate on: an order is the buyer's own record, not content written in one language - what is read around it is this bundle's interface, which ships as a catalogue per language
    /** @return list<string> */
    private function everyLanguage(): array
    {
        return $this->siteLocales->all();
    }

    // ORDER HISTORY - the same history, in another language: a buyer reading the site in English has no reason to be handed their orders in the writing language, the menu leading here from every page
    #[Route('/{_locale}/account/orders', name: 'customer_orders_localized', requirements: ['_locale' => '%c975l_config.locales_pattern%'], methods: ['GET'])]
    #[Route('/account/orders', name: 'customer_orders', methods: ['GET'])]
    public function orders(Request $request): Response
    {
        $askedLanguage = $this->negotiator->redirectToAskedLanguage($request, $this->everyLanguage(), 'customer_orders');
        if (null !== $askedLanguage) {
            return $this->negotiator->vary($request, $askedLanguage);
        }

        return $this->negotiator->vary($request, $this->render('@c975LPayment/customer/orders.html.twig', [
            'baskets' => $this->basketRepository->findPaidByUser($this->getUser()),
        ]));
    }

    // ONE ORDER
    #[Route('/{_locale}/account/orders/{number}', name: 'customer_order_localized', requirements: ['_locale' => '%c975l_config.locales_pattern%', 'number' => '.{15,20}'], methods: ['GET'])]
    #[Route('/account/orders/{number}', name: 'customer_order', requirements: ['number' => '.{15,20}'], methods: ['GET'])]
    public function order(string $number, Request $request): Response
    {
        $askedLanguage = $this->negotiator->redirectToAskedLanguage($request, $this->everyLanguage(), 'customer_order', ['number' => $number]);
        if (null !== $askedLanguage) {
            return $this->negotiator->vary($request, $askedLanguage);
        }

        $basket = $this->basketRepository->findOneBy(['number' => $number]);

        // Order numbers run in sequence, so one is guessed from the next: a basket that is not this user's own is answered as missing rather than as forbidden, which would confirm it exists
        if (!$basket instanceof Basket || $basket->getUser() !== $this->getUser() || !\in_array($basket->getStatus(), ['paid', 'shipped'], true)) {
            throw new NotFoundHttpException();
        }

        return $this->negotiator->vary($request, $this->render('@c975LPayment/customer/order.html.twig', [
            'basket' => $basket,
            'downloads' => $this->basketDownloadRegistry->getDownloads($basket),
        ]));
    }
}
