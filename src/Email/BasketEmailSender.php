<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Email;

use c975L\PaymentBundle\Entity\Basket;
use c975L\UiBundle\Service\EmailService;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// The one place a basket email is written in the customer's language rather than in whoever's language happens to be current: a reminder goes out from a nightly command and a shipping notice from the shopkeeper's click, and the order is the only thing that remembers what language it was placed in
class BasketEmailSender
{
    public function __construct(
        private readonly BasketEmailFactory $basketEmailFactory,
        private readonly EmailService $emailService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Sends one basket email, and says whether it left.
     *
     * The whole build and send happens inside the language window on purpose: the subject, the fragments and - on
     * the Twig path, which renders eagerly under "wrapLayout" - the body itself are all translated as they are
     * built, so nothing is left to be rendered later by a worker that would know nothing of this customer.
     *
     * @param array<string, mixed> $context what the body template and the slots need on top of the basket
     * @param ?string              $to      an address other than the buyer's - see BasketEmailFactory::create()
     */
    public function send(Basket $basket, string $subjectKey, string $template, array $context = [], ?string $to = null): bool
    {
        $previous = $this->useLocale($basket->getLocale());

        try {
            return $this->emailService->send($this->basketEmailFactory->create($basket, $subjectKey, $template, $context, $to));
        } finally {
            $this->useLocale($previous);
        }
    }

    public function getLastError(): ?string
    {
        return $this->emailService->getLastError();
    }

    // Switches the translator over and hands back what it was on, so a French order following an English one is not written in English
    private function useLocale(?string $locale): ?string
    {
        if (null === $locale || '' === $locale || !$this->translator instanceof LocaleAwareInterface) {
            return null;
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);

        return $previous;
    }
}
