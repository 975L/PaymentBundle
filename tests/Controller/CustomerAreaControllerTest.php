<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\ConfigBundle\Service\LocalizedRouteNegotiator;
use c975L\ConfigBundle\Service\SiteLocales;
use c975L\PaymentBundle\Controller\CustomerAreaController;
use c975L\PaymentBundle\Registry\BasketDownloadRegistry;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;

// A buyer who asked for English is moved from the bare url of their orders to its English twin, before anything of theirs is read
class CustomerAreaControllerTest extends TestCase
{
    // The history, asked for in English on its bare url
    public function testTheOrderHistoryMovesABuyerWhoAskedForAnotherLanguage(): void
    {
        $response = $this->controller()->orders($this->askingForEnglish());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/customer_orders_localized?_locale=en', $response->getTargetUrl());
        $this->assertSame('Accept-Language', $response->headers->get('Vary'));
    }

    // One order: its number travels with the redirect, or the buyer would land on a 404
    public function testOneOrderMovesABuyerWhoAskedForAnotherLanguageWithItsNumber(): void
    {
        $response = $this->controller()->order('ABC123DEF456GHI', $this->askingForEnglish());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/customer_order_localized?number=ABC123DEF456GHI&_locale=en', $response->getTargetUrl());
    }

    // A bare url with "?_locale=en", what a first click on the language menu sends
    private function askingForEnglish(): Request
    {
        $request = new Request(['_locale' => 'en']);
        $request->setLocale('en');

        return $request;
    }

    // A site written in French and read in English too, its router naming the route and its parameters
    private function controller(): CustomerAreaController
    {
        $siteLocales = new SiteLocales(['fr', 'en'], 'fr');

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => '/' . $name . '?' . http_build_query($parameters)
        );

        return new CustomerAreaController(
            $this->createStub(BasketRepository::class),
            $this->createStub(BasketDownloadRegistry::class),
            new LocalizedRouteNegotiator($siteLocales, new LocaleSwitcher('fr', []), $router),
            $siteLocales,
        );
    }
}
