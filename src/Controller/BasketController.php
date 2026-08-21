<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Exception\BasketNotOrderableException;
use c975L\PaymentBundle\Exception\PaymentUnavailableException;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Main Controller class.
 *
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2024 975L <contact@975l.com>
 */
class BasketController extends AbstractController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly BasketServiceInterface $basketService,
        private readonly BasketRecommendationRegistry $recommendationRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // GETS BASKET JSON
    #[Route(
        '/shop/basket/json',
        name: 'basket_json',
        methods: ['GET']
    )]
    public function getJson(): JsonResponse
    {
        return new JsonResponse($this->basketService->getJson());
    }

    // DISPLAY
    #[Route(
        '/shop/basket/display',
        name: 'basket_display',
        methods: ['GET']
    )]
    public function display()
    {
        $basket = $this->basketService->get();

        $recommendations = [];

        if ($basket && !empty($basket->getItems())) {
            $recommendations = $this->recommendationRegistry->getRecommendations($basket, 4);
        }

        // Renders the page
        return $this->render('@c975LPayment/basket/display.html.twig', [
            'action' => 'display',
            'basket' => $basket,
            'recommendations' => $recommendations,
        ]);
    }

    // VALIDATE
    #[Route(
        '/shop/basket/validate',
        name: 'basket_validate',
        methods: ['GET', 'POST']
    )]
    public function validate(Request $request): Response
    {
        $basket = $this->basketService->get();

        if (null === $basket) {
            return $this->redirectToRoute('basket_display', [], Response::HTTP_SEE_OTHER);
        }

        // Defines form
        $form = $this->basketService->createForm('coordinates', $basket);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // A shop whose payment keys are missing takes no order rather than answering a 500: the basket is untouched and the same thing is said on the dashboard (see PaymentAlertProvider)
            try {
                $url = $this->basketService->validate($request);
            } catch (PaymentUnavailableException) {
                $this->addFlash('danger', $this->translator->trans('flash.payment_unavailable', [], 'payment'));

                return $this->redirectToRoute('basket_display', [], Response::HTTP_SEE_OTHER);
            } catch (BasketNotOrderableException $exception) {
                // The provider's own message, already translated: only the bundle owning the item can say whether it ran out, was withdrawn or was taken offline. The basket is untouched, so the visitor comes back to it and takes out what no longer holds
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('basket_display', [], Response::HTTP_SEE_OTHER);
            }

            return $this->redirect($url, Response::HTTP_SEE_OTHER);
        }

        // Renders the page
        return $this->render('@c975LPayment/basket/display.html.twig', [
            'action' => 'validate',
            'form' => $form->createView(),
            'basket' => $basket,
        ]);
    }

    // PAID
    #[Route(
        '/shop/basket/paid/{number:basket}/{securityToken:basket}',
        name: 'basket_paid',
        requirements: [
            'number' => '.{15,20}',
            'securityToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET']
    )]
    public function paid(?Basket $basket, Request $request): Response
    {
        if (null !== $basket) {
            $this->basketService->confirmReturn($basket, $request);
        }

        return $this->render('@c975LPayment/basket/display.html.twig', [
            'action' => 'paid',
            'basket' => $basket,
        ]);
    }

    // ADD PRODUCT ITEM
    #[Route(
        '/shop/basket',
        name: 'basket_add',
        methods: ['POST']
    )]
    public function add(Request $request): JsonResponse
    {
        return new JsonResponse($this->basketService->addItem($request));
    }

    // DELETE PRODUCT ITEM
    #[Route(
        '/shop/basket/delete',
        name: 'basket_product_delete',
        methods: ['DELETE']
    )]
    public function remove(Request $request): JsonResponse
    {
        return new JsonResponse($this->basketService->deleteItem($request));
    }

    // DELETE
    #[Route(
        '/shop/basket',
        name: 'basket_delete',
        methods: ['DELETE']
    )]
    public function delete(): JsonResponse
    {
        return new JsonResponse($this->basketService->delete());
    }

    // ITEMS SHIPPED
    #[Route(
        '/shop/basket/items-shipped/{number}/{type}',
        name: 'items_shipped',
        requirements: [
            'number' => '.{15,20}',
            'type' => 'product|crowdfunding',
        ],
        methods: ['GET']
    )]
    public function itemsShipped(string $number, string $type): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $basket = $this->basketService->itemsShipped($number, $type);

        return $this->render(
            '@c975LPayment/basket/shipped.html.twig',
            [
                'basket' => $basket,
            ]
        )->setMaxAge(3600);
    }
}
