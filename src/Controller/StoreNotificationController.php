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
use c975L\PaymentBundle\Registry\InAppStoreRegistry;
use c975L\PaymentBundle\Service\InAppPurchaseServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// What the stores say after a sale - a refund above all - on one url per store, the same shape as PaymentWebhookController: the payload is read by the store the url names, never by the site
class StoreNotificationController extends AbstractController
{
    public function __construct(
        private readonly InAppStoreRegistry $storeRegistry,
        private readonly InAppPurchaseServiceInterface $purchaseService,
        private readonly LoggerInterface $logger,
    ) {
    }

    // The url given to App Store Connect ("App Store Server Notifications", version 2) and to the Pub/Sub push subscription of Google Play, the latter with ?token= appended
    #[Route('/payment/store/{store}', name: 'payment_store_notification', methods: ['POST'])]
    public function notify(Request $request, string $store): Response
    {
        if (!$this->storeRegistry->has($store)) {
            return new Response('Unknown store', 404);
        }

        try {
            $transaction = $this->storeRegistry->get($store)->readNotification($request);
            if (null !== $transaction) {
                $this->purchaseService->applyNotification($transaction);
            }

            return new Response('Notification received', 200);
        } catch (InvalidNotificationException $e) {
            $this->logger->warning('Store notification refused', ['store' => $store, 'error' => $e->getMessage()]);

            return new Response('Invalid payload', 400);
        } catch (\Throwable $e) {
            $this->logger->error('Store notification failed', ['store' => $store, 'error' => $e->getMessage()]);

            return new Response('Notification failed', 500);
        }
    }
}
