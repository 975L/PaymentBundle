<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller;

use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\BasketServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// One endpoint per provider, the payload read by the gateway the url names - the site never parses a provider's event shape itself
class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly BasketServiceInterface $basketService,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    // One url for every provider, the gateway named by the path. ShopBundle's own /shop/stripe/webhook was kept alongside it for a while so a shop upgrading had a day to move its dashboard over; it is gone now, and a site still declaring it at Stripe collects a 404 on every event until it points at this one
    #[Route('/payment/webhook/{gateway}', name: 'payment_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request, string $gateway): Response
    {
        if (!$this->gatewayRegistry->has($gateway)) {
            return new Response('Unknown gateway', 404);
        }

        try {
            $notification = $this->gatewayRegistry->get($gateway)->readNotification($request);
            if (null !== $notification) {
                $this->basketService->applyNotification($notification);
            }

            return new Response('Webhook received', 200);
        } catch (InvalidNotificationException $e) {
            $this->logger->warning('Payment webhook refused', ['gateway' => $gateway, 'error' => $e->getMessage()]);

            return new Response('Invalid payload', 400);
        } catch (\Exception $e) {
            $this->logger->error('Payment webhook failed', ['gateway' => $gateway, 'error' => $e->getMessage()]);

            // The provider is told to replay, and nothing more: its own dashboard shows what the site answered, and what went wrong here belongs in the log rather than on the wire
            return new Response('Webhook failed', 500);
        }
    }
}
