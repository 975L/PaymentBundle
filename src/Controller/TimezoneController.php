<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

// The browser's timezone, held in the session so the dates this bundle prints - an order registered, items shipped, a download - read in the hour of whoever is reading them. It lives here rather than in a satellite bundle because it is this bundle's own basket controller that sends it (see assets/js/basket.js) and its own templates that read it back
class TimezoneController extends AbstractController
{
    #[Route(
        '/set-timezone',
        name: 'set_timezone',
        methods: ['POST']
    )]
    public function setTimezone(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Only a known identifier is kept, a poisoned session otherwise breaking every page that prints an hour until it is emptied
        $timezone = \is_string($data['timezone'] ?? null) && \in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true) ? $data['timezone'] : 'Europe/Paris';

        // Stores in session
        $request->getSession()->set('user_timezone', $timezone);

        return new JsonResponse(['success' => true]);
    }
}
