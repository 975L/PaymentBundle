<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Email;

use c975L\PaymentBundle\Email\PaymentEmailTemplateProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The seam between what an admin composes and what the code fills in.
 *
 * A slot block names a fragment: get the name wrong and nothing is reported, the block simply renders nothing and
 * the order's lines quietly vanish from the confirmation. So the names are checked against the templates that
 * render them, in both directions.
 *
 * Built against this bundle's real catalogues rather than a stub translator, the sentences now being read from them:
 * a mistyped key is not an error anywhere, trans() hands back the key itself and it would be that string - not the
 * sentence - that gets seeded into a site and mailed to a customer.
 */
class PaymentEmailTemplateProviderTest extends TestCase
{
    private const string SLOTS_DIR = __DIR__ . '/../../templates/emails/slots';

    // Every fragment a seeded template asks for is one this bundle knows how to render
    public function testEverySlotNameHasATemplateRenderingIt(): void
    {
        foreach ($this->declaredSlotNames() as $name) {
            $this->assertFileExists(self::SLOTS_DIR . '/' . $name . '.html.twig', sprintf('Slot "%s" is composed into an email but nothing renders it', $name));
        }
    }

    // And the other way round, so a fragment nobody places any more is noticed rather than rendered for nothing on every send
    public function testEverySlotTemplateIsPlacedInAtLeastOneEmail(): void
    {
        $declared = $this->declaredSlotNames();

        foreach (glob(self::SLOTS_DIR . '/*.html.twig') as $path) {
            $name = basename($path, '.html.twig');
            $this->assertContains($name, $declared, sprintf('Slot "%s" is rendered on every send and placed in no email', $name));
        }
    }

    // The seven emails, each written in the three languages this bundle ships
    public function testEveryEmailIsDeclaredInEveryLanguageTheBundleShips(): void
    {
        $templates = $this->provider()->getEmailTemplates();

        $this->assertCount(7, $templates);
        foreach ($templates as $name => $blocksByLocale) {
            $this->assertSame(['fr', 'en', 'es'], array_keys($blocksByLocale), $name);
        }
    }

    // Nothing composed into an email is a translation key left unresolved, in any of the three languages
    public function testEverySentenceIsTranslatedInEveryLanguage(): void
    {
        foreach ($this->provider()->getEmailTemplates() as $name => $blocksByLocale) {
            foreach ($blocksByLocale as $locale => $blocks) {
                foreach ($blocks as [$type, $heading, , $content, $label]) {
                    foreach ([$heading, $content, 'slot' === $type ? null : $label] as $wording) {
                        $this->assertDoesNotMatchRegularExpression(
                            '/^(label|text)\./',
                            (string) $wording,
                            sprintf('"%s" (%s, %s) holds an untranslated key, which would be mailed as-is', $wording, $name, $locale)
                        );
                    }
                }
            }
        }
    }

    // Read from translations/, so a catalogue and a declaration cannot drift the way this bundle's Twig bodies had
    private function provider(): PaymentEmailTemplateProvider
    {
        $translator = new Translator('fr');
        $translator->addLoader('xlf', new XliffFileLoader());
        foreach (['fr', 'en', 'es'] as $locale) {
            $translator->addResource('xlf', __DIR__ . '/../../translations/payment.' . $locale . '.xlf', $locale, 'payment');
        }

        return new PaymentEmailTemplateProvider($translator);
    }

    /** @return string[] */
    private function declaredSlotNames(): array
    {
        $names = [];
        foreach ($this->provider()->getEmailTemplates() as $blocksByLocale) {
            foreach ($blocksByLocale as $blocks) {
                foreach ($blocks as [$type, , , , $label]) {
                    if ('slot' === $type) {
                        $names[] = $label;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }
}
