<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Service\Tools;

/**
 * Interface to be called for DI for Payment Tools related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface PaymentToolsInterface
{
    /**
     * Creates flash message
     */
    public function createFlash(string $object, array $options);

    /**
     * Creates flash for error
     */
    public function createFlashError(array $errData);
}
