<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller;

use c975L\PaymentBundle\Controller\TimezoneController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The hour the dates of this bundle are printed in, taken from the browser that is reading them.
 *
 * What is pinned here is the exchange itself: the timezone the page sends is the one the session holds,
 * a request saying nothing leaves the site's own hour in place rather than an empty one, and the address
 * answering all this is the one the basket controller calls - the two having lived in separate bundles once.
 */
class TimezoneControllerTest extends TestCase
{
    // The timezone read off the browser is what the session holds, the dates being printed in it afterwards
    public function testTheTimezoneSentIsTheOneTheSessionHolds(): void
    {
        $request = $this->request('{"timezone":"America/Martinique"}');

        $response = new TimezoneController()->setTimezone($request);

        $this->assertSame('America/Martinique', $request->getSession()->get('user_timezone'));
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));
    }

    // A body carrying nothing usable - empty, or without the key - falls back on the hour the templates already print without a session
    public function testARequestSayingNothingFallsBackOnTheSitesOwnHour(): void
    {
        foreach (['', '{}', 'not json'] as $content) {
            $request = $this->request($content);

            new TimezoneController()->setTimezone($request);

            $this->assertSame('Europe/Paris', $request->getSession()->get('user_timezone'), sprintf('Body "%s" stored something else', $content));
        }
    }

    // A session holding an identifier DateTimeZone refuses breaks every page printing an hour, and only emptying it gets out - so what is not an identifier is not kept
    public function testAnIdentifierNobodyKnowsIsNotKept(): void
    {
        foreach (['{"timezone":"Mars/Olympus"}', '{"timezone":""}', '{"timezone":42}', '{"timezone":["Europe/Paris"]}'] as $content) {
            $request = $this->request($content);

            $response = new TimezoneController()->setTimezone($request);

            $this->assertSame('Europe/Paris', $request->getSession()->get('user_timezone'), sprintf('Body "%s" stored something else', $content));
            $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));
        }
    }

    // The address is written in assets/js/basket.js as a string, so a route renamed here answers nothing there
    public function testTheAddressIsTheOneTheBasketControllerCalls(): void
    {
        $attribute = new \ReflectionMethod(TimezoneController::class, 'setTimezone')->getAttributes(Route::class)[0] ?? null;

        $this->assertNotNull($attribute, 'setTimezone() carries no route');

        $arguments = $attribute->getArguments();

        $this->assertSame('/set-timezone', $arguments['path'] ?? $arguments[0]);
        $this->assertSame(['POST'], $arguments['methods']);
    }

    private function request(string $content): Request
    {
        $request = Request::create('/set-timezone', 'POST', [], [], [], [], $content);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
