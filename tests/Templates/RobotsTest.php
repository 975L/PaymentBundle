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

// Every page this bundle serves a visitor is that one visitor's own - a basket, an order, a card - and an indexed copy of any of them is either an empty cart standing for a page of the shop, or an order handed to whoever searched for it. The layout reads "robots", so a page not setting it is offered to the indexes by default and nothing says so
class RobotsTest extends TestCase
{
    /**
     * The templates extending the site layout, which are the pages a visitor is served.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $directory = new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/templates');
        $pages = [];
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.html.twig')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, "{% extends 'layout.html.twig' %}")) {
                $pages[$file->getFilename()] = $content;
            }
        }

        return $pages;
    }

    // The whole set, so a page added tomorrow is caught rather than a list needing to be extended by hand
    public function testEveryPageDeclaresItsRobots(): void
    {
        $pages = $this->pages();

        $this->assertNotSame([], $pages);
        foreach ($pages as $name => $content) {
            $this->assertMatchesRegularExpression("/\{% set robots = '(noindex, follow|noindex, nofollow)' %\}/", $content, sprintf('%s is served to a visitor and says nothing of the indexes', $name));
        }
    }
}
