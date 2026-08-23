<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\PaymentBundle\Entity\GiftCard;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// The card as whoever it was given to sees it, which is nobody the site knows: a card is bought for someone else, so its page asks for no account and is reached by an address of its own instead (see GiftCard::$shareToken)
class GiftCardController extends AbstractController
{
    // The card cut out of an A4, which is the only sheet a home printer takes - the two faces are drawn at the 85.6 by 54 millimetres of an ID-1 card, one above the other, inside a cutting line
    private const string PDF_TEMPLATE = '@c975LPayment/gift_card/pdf.html.twig';

    public function __construct(
        private readonly PdfGeneratorInterface $pdfGenerator,
    ) {
    }

    // THE CARD ITSELF
    // The token and never the code: an address travels through browser histories, referrers, chat servers and link previews, and a code read off one of those logs is a balance spent by somebody who never held the card
    #[Route(
        '/gift-card/{shareToken:giftCard}',
        name: 'gift_card_display',
        requirements: ['shareToken' => '[a-f0-9]{32}'],
        methods: ['GET']
    )]
    public function display(GiftCard $giftCard): Response
    {
        $response = $this->render('@c975LPayment/gift_card/display.html.twig', [
            'giftCard' => $giftCard,
        ]);

        return $this->headers($response);
    }

    // THE CODE UNDER THE PANEL
    // Asked for rather than written in the page: the address is meant to be forwarded, and a link pasted into a chat is fetched by a robot that reads the markup and runs no script. Nothing here is a check the display above skipped - both hand out what the same address already gives - it is the markup that holds no code until this is asked
    #[Route(
        '/gift-card/{shareToken:giftCard}/code',
        name: 'gift_card_reveal',
        requirements: ['shareToken' => '[a-f0-9]{32}'],
        methods: ['GET']
    )]
    public function reveal(GiftCard $giftCard): Response
    {
        // A card taken out of circulation - one reported stolen - shows its visual and its balance and hands out no code: the page is what the holder was given, the code is what spends the money
        if (!$giftCard->isActive()) {
            return $this->headers(new JsonResponse(['error' => 'inactive'], Response::HTTP_FORBIDDEN));
        }

        return $this->headers(new JsonResponse(['code' => $giftCard->getCode()]));
    }

    // THE CARD AS A FILE
    // What a card printed, posted or simply kept is: a document holds no fold and no panel to rub off, so its code is on it - which is why it is refused on a card switched off, exactly like the panel above
    #[Route(
        '/gift-card/{shareToken:giftCard}/pdf',
        name: 'gift_card_pdf',
        requirements: ['shareToken' => '[a-f0-9]{32}'],
        methods: ['GET']
    )]
    public function pdf(GiftCard $giftCard): Response
    {
        if (!$giftCard->isActive()) {
            throw new NotFoundHttpException();
        }

        $response = new Response($this->pdfGenerator->render(self::PDF_TEMPLATE, ['giftCard' => $giftCard]));
        $response->headers->set('Content-Type', 'application/pdf');
        // "inline": a card is looked at before it is saved, and a browser showing it is what lets the holder check it is the right one
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('inline', sprintf('carte-cadeau-%s.pdf', $giftCard->getCode())));

        return $this->headers($response);
    }

    // What a page carrying a bearer instrument answers with: out of every index, out of every referrer, and out of every cache - the balance changes, and a shared machine must not hand the next visitor the page the last one opened
    private function headers(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');

        return $response;
    }
}
