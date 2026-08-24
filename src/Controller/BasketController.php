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
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Registry\BasketRecommendationRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use c975L\PaymentBundle\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly BasketDownloadRegistry $basketDownloadRegistry,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceService $invoiceService,
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
                // Two buttons, one form: the coordinates are the recipient's either way, only who is asked for the money changes
                $url = $this->basketService->validate($request, $request->request->has('share'));
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

    // INVOICE - the order's invoice as a file, opened by the customer from their own order page
    #[Route(
        '/shop/basket/invoice/{number:basket}/{securityToken:basket}',
        name: 'basket_invoice_pdf',
        requirements: [
            'number' => '.{15,20}',
            'securityToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET']
    )]
    public function invoice(?Basket $basket): Response
    {
        // Numbered when it was paid, so an order still being settled has nothing to show and a wrong token has nothing to guess at
        $pdf = null === $basket ? null : $this->invoiceService->pdf($basket);

        if (null === $pdf) {
            throw $this->createNotFoundException();
        }

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $this->invoiceService->filename($basket)));

        // Somebody's name, address and what they bought: never cached anywhere but the browser that asked for it, and never indexed
        $response->headers->set('X-Robots-Tag', 'noindex,nofollow,noarchive');
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');

        return $response;
    }

    // SHARED - the customer's own page once they asked somebody else to settle their order, where they copy the link to send
    #[Route(
        '/shop/basket/shared/{number:basket}/{securityToken:basket}',
        name: 'basket_shared',
        requirements: [
            'number' => '.{15,20}',
            'securityToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET']
    )]
    public function shared(?Basket $basket): Response
    {
        // The security token is the customer's own, so this page may show everything - it is the payer's page below that shows nothing of the address
        if (null === $basket || !$basket->isShared()) {
            throw $this->createNotFoundException();
        }

        return $this->render('@c975LPayment/basket/shared.html.twig', [
            'basket' => $basket,
            // The short address, which is the one the customer is going to send: they copy it into a text message as readily as into an e-mail, and it is the same page either way (see shortPay())
            'payUrl' => $this->generateUrl('basket_short_pay', ['shareToken' => $basket->getShareToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    // SHORT PAY - the same page at an address short enough to travel in a text message, which is how a payment link is often sent
    /**
     * The share token alone names the order, being unique in its own right - the number beside it in the long
     * address guards nothing, and dropping it takes 28 characters off a link that has 160 to live in.
     *
     * A redirection and not a second page: the "already settled", the "no longer available" and the noindex
     * headers stay written once, where the payer's page has always held them. Sixty-four bits are what a shared
     * order has always been reached by, and are not enumerable - GiftCard is reached by its own token in the
     * same way.
     */
    #[Route(
        '/pay/{shareToken}',
        name: 'basket_short_pay',
        requirements: ['shareToken' => '[a-fA-F0-9]{16}'],
        methods: ['GET']
    )]
    public function shortPay(string $shareToken, BasketRepository $basketRepository): Response
    {
        // Read here rather than left to the route's own resolution: this address is dictated over the telephone and retyped, and a token capitalised on the way must open the order it names. Lower-cased before the query and not left to the database, a site whose collation is binary telling the two apart where another does not
        $basket = $basketRepository->findOneBy(['shareToken' => strtolower($shareToken)]);

        if (null === $basket || !$basket->isShared()) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'basket_shared_pay',
            [
                'number' => $basket->getNumber(),
                'shareToken' => $basket->getShareToken(),
            ],
            Response::HTTP_FOUND
        );
    }

    // SHARED PAY - the payer's own page: what is being bought and what it costs, and nothing of who it is for
    #[Route(
        '/shop/basket/pay/{number:basket}/{shareToken:basket}',
        name: 'basket_shared_pay',
        requirements: [
            'number' => '.{15,20}',
            'shareToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET', 'POST']
    )]
    public function sharedPay(?Basket $basket, Request $request): Response
    {
        if (null === $basket || !$basket->isShared()) {
            throw $this->createNotFoundException();
        }

        // Already settled, or taken back to a basket by its customer: the page says so and offers nothing to pay, rather than opening a second checkout
        $payable = 'validated' === $basket->getStatus() && $basket->getPayable() > 0;

        if ($request->isMethod('POST') && $payable) {
            try {
                return $this->redirect($this->basketService->payShared($basket), Response::HTTP_SEE_OTHER);
            } catch (PaymentUnavailableException) {
                $this->addFlash('danger', $this->translator->trans('flash.payment_unavailable', [], 'payment'));
            }
        }

        return $this->render('@c975LPayment/basket/shared_pay.html.twig', [
            'basket' => $basket,
            'payable' => $payable,
            'settled' => in_array($basket->getStatus(), ['paid', 'shipped'], true),
            // Followed from a reminder, the page is the customer's own way back and not a payer's: same order, same button, other words
            'fromReminder' => $request->query->getBoolean('reminder'),
        ]);
    }

    // SHARED PAID - where the payer lands once the provider is done with them, which is never the customer's own order page
    #[Route(
        '/shop/basket/pay/{number:basket}/{shareToken:basket}/done',
        name: 'basket_shared_paid',
        requirements: [
            'number' => '.{15,20}',
            'shareToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET']
    )]
    public function sharedPaid(?Basket $basket, Request $request): Response
    {
        if (null === $basket || !$basket->isShared()) {
            throw $this->createNotFoundException();
        }

        $this->basketService->confirmReturn($basket, $request);

        return $this->render('@c975LPayment/basket/shared_paid.html.twig', [
            'basket' => $basket,
            'confirmed' => in_array($basket->getStatus(), ['paid', 'shipped'], true),
        ]);
    }

    // REMINDER UNSUBSCRIBE - the way out carried by every reminder of an unpaid order
    #[Route(
        '/shop/basket/pay/{number:basket}/{shareToken:basket}/no-reminder',
        name: 'basket_reminder_unsubscribe',
        requirements: [
            'number' => '.{15,20}',
            'shareToken' => '[a-f0-9]{16}',
        ],
        methods: ['GET']
    )]
    public function unsubscribeReminder(?Basket $basket, EntityManagerInterface $entityManager): Response
    {
        if (null === $basket || !$basket->isShared()) {
            throw $this->createNotFoundException();
        }

        // No confirmation step: the link is in the recipient's own e-mail and asking to hear no more is what they came for, where a second click is what makes people mark the message as spam instead. Written again on a second visit rather than left alone, the date being of no use beyond saying the opposition was made
        $basket->setReminderOptOutAt(new \DateTime());
        $entityManager->flush();

        return $this->render('@c975LPayment/basket/reminder_unsubscribed.html.twig');
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

        // The files are offered on the page itself, so a buyer whose email never arrived still gets them - read only once the payment is confirmed, never for a basket still being paid
        $confirmed = null !== $basket && in_array($basket->getStatus(), ['paid', 'shipped'], true);

        return $this->render('@c975LPayment/basket/display.html.twig', [
            'action' => 'paid',
            'basket' => $basket,
            'downloads' => $confirmed ? $this->basketDownloadRegistry->getDownloads($basket) : [],
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

    // APPLY A CODE (promotional code or gift card - the service tells them apart)
    #[Route(
        '/shop/basket/code',
        name: 'basket_code_apply',
        methods: ['POST']
    )]
    public function applyCode(Request $request): JsonResponse
    {
        return new JsonResponse($this->basketService->applyCode($request));
    }

    // REMOVE THAT CODE
    #[Route(
        '/shop/basket/code',
        name: 'basket_code_remove',
        methods: ['DELETE']
    )]
    public function removeCode(): JsonResponse
    {
        return new JsonResponse($this->basketService->removeCode());
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

        // Not cached for an hour as it used to be: this page reports a write, and the admin's own browser served its copy back rather than the state of the order they had just changed - the same copy standing in for the page that carries what the email debug mode held back
        return $this->render(
            '@c975LPayment/basket/shipped.html.twig',
            [
                'basket' => $basket,
            ]
        );
    }
}
