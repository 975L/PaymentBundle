<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\PaymentBundle\Controller\GiftCardController;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * The page whoever a card was given to opens, reached by an address of its own and by no account at all.
 *
 * What is pinned here is what that page must never leak: it stays out of every index, out of every referrer
 * and out of every cache, and the code it hides is handed over by a request of its own - which a card taken
 * out of circulation is refused.
 */
class GiftCardControllerTest extends TestCase
{
    // Out of the indexes, out of the referrers, out of the caches: the address is the card, and a balance changes
    public function testThePageSaysItIsNeitherIndexedNorCachedNorReferred(): void
    {
        $response = $this->controller()->display($this->giftCard());

        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // The code is not in the page: it is asked for once the panel is rubbed off, which is what keeps it out of every link preview
    public function testThePanelHandsTheCodeOverOnItsOwnRequest(): void
    {
        $response = $this->controller()->reveal($this->giftCard());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['code' => 'ABCDEFGHJKLMNPQR'], json_decode((string) $response->getContent(), true));
    }

    // A card reported stolen keeps its page - its holder still reads its visual and its balance - and hands out no code
    public function testACardTakenOutOfCirculationShowsItselfButHandsOutNoCode(): void
    {
        $giftCard = $this->giftCard()->setActive(false);

        $response = $this->controller()->reveal($giftCard);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringNotContainsString('ABCDEFGHJKLMNPQR', (string) $response->getContent());
    }

    // The refusal is answered with the very same headers: an error page carrying a card's address is cached like any other otherwise
    public function testTheRefusalIsAnsweredWithTheSameHeaders(): void
    {
        $response = $this->controller()->reveal($this->giftCard()->setActive(false));

        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // A file the holder keeps: no fold and no panel to rub off, so the code is printed on it - which is why it is refused on a card switched off, exactly like the panel
    public function testTheCardIsHandedOverAsAPrintableFile(): void
    {
        $response = $this->controller()->pdf($this->giftCard());

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('carte-cadeau-ABCDEFGHJKLMNPQR.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testACardTakenOutOfCirculationIsPrintedByNobody(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->pdf($this->giftCard()->setActive(false));
    }

    private function giftCard(): GiftCard
    {
        return new GiftCard()
            ->setCode('ABCDEFGHJKLMNPQR')
            ->setShareToken(str_repeat('a', 32))
            ->setInitialAmount(3000)
            ->setBalance(3000)
            ->setCurrency('EUR')
        ;
    }

    private function controller(): GiftCardController
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html lang="fr"></html>');

        $services = ['twig' => $twig];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        $controller = new GiftCardController($this->createStub(PdfGeneratorInterface::class));
        $controller->setContainer($container);

        return $controller;
    }
}
