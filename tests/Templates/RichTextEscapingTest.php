<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

// What an editor writes reaches a page or a PDF through the sanitizer; the two |raw left print a translation holding nothing a visitor or an editor typed
class RichTextEscapingTest extends TestCase
{
    private const array RAW_ALLOWED = [
        'components/Basket/Validation.html.twig' => 1,
        'emails/slots/reminder_unsubscribe.html.twig' => 1,
    ];

    public function testNothingElseIsPrintedRaw(): void
    {
        $raw = [];
        foreach (new Finder()->files()->in(\dirname(__DIR__, 2) . '/templates')->name('*.twig') as $file) {
            $source = (string) preg_replace('/\{#.*?#\}/s', '', $file->getContents());
            $count = preg_match_all('/\|\s*raw\b/', $source);
            if ($count > 0 && $count !== (self::RAW_ALLOWED[$file->getRelativePathname()] ?? 0)) {
                $raw[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $raw);
    }

    // Rendered and not read as a string: nl2br escapes what is not safe, so a filter chain in the wrong order prints the tags as text, and a field left empty ends the page on the filter's TypeError
    public function testACounterpartDescriptionReachesThePageSanitizedWithItsMarkupAndItsLineBreaks(): void
    {
        preg_match('/\{\{ counterpart\.item\.description.*?\}\}/', (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/components/Basket/Counterpart.html.twig'), $matches);

        $twig = new Environment(new ArrayLoader(['line' => $matches[0]]));
        $twig->addFilter(new TwigFilter('sanitize_html', static fn (string $html, ?string $name = null): string => (string) preg_replace('#<img[^>]*>#', '', $html), ['is_safe' => ['html']]));

        $html = $twig->render('line', ['counterpart' => ['item' => ['description' => "<strong>Signed</strong><img src=x onerror=alert(1)>\ncopy"]]]);

        $this->assertSame("<strong>Signed</strong><br />\ncopy", $html);
        $this->assertSame('', $twig->render('line', ['counterpart' => ['item' => ['description' => null]]]));
    }
}
