<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    // ORDER HISTORY
    #[Route('/account/orders', name: 'customer_orders', methods: ['GET'])]
    public function orders(): Response
    {
        return $this->render('@c975LPayment/customer/orders.html.twig', [
            'baskets' => $this->basketRepository->findPaidByUser($this->getUser()),
        ]);
    }

    // ONE ORDER
    #[Route('/account/orders/{number}', name: 'customer_order', requirements: ['number' => '.{15,20}'], methods: ['GET'])]
    public function order(string $number): Response
    {
        $basket = $this->basketRepository->findOneBy(['number' => $number]);

        // Order numbers run in sequence, so one is guessed from the next: a basket that is not this user's own is answered as missing rather than as forbidden, which would confirm it exists
        if (!$basket instanceof Basket || $basket->getUser() !== $this->getUser() || !\in_array($basket->getStatus(), ['paid', 'shipped'], true)) {
            throw new NotFoundHttpException();
        }

        return $this->render('@c975LPayment/customer/order.html.twig', [
            'basket' => $basket,
            'downloads' => $this->basketDownloadRegistry->getDownloads($basket),
        ]);
    }
}
