<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Assets;

use c975L\UiBundle\Testing\JsCase as UiJsCase;

/**
 * This bundle's own javascript, run through the harness c975L/UiBundle ships.
 *
 * The whole of what a bundle has to declare is where its assets are: its controllers are then served
 * beside UiBundle's, the bare "@hotwired/stimulus" they import is rewritten towards the Stimulus vendored
 * there, and a scenario reads back what they made of the DOM. Any bundle depending on c975L/UiBundle
 * writes exactly this class and nothing more.
 *
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
abstract class JsCase extends UiJsCase
{
    protected function bundleRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
