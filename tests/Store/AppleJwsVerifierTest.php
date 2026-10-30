<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Store;

use c975L\PaymentBundle\Exception\InvalidNotificationException;
use c975L\PaymentBundle\Store\AppleJwsVerifier;
use c975L\PaymentBundle\Store\Jwt;
use PHPUnit\Framework\TestCase;

// What is accepted as signed by Apple: only a chain leading to the expected root, marked as Apple marks it, with a signature matching the payload
class AppleJwsVerifierTest extends TestCase
{
    public function testAPayloadSignedThroughTheChainIsRead(): void
    {
        $chain = new AppleTestChain();

        $this->assertSame(['productId' => 'back.a'], new AppleJwsVerifier($chain->rootPath)->verify($chain->sign(['productId' => 'back.a'])));
    }

    // A chain built by anybody, however well formed, does not lead to the root the site trusts
    public function testAChainOfAnotherRootIsRefused(): void
    {
        $trusted = new AppleTestChain();
        $other = new AppleTestChain();

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('root certificate');
        new AppleJwsVerifier($trusted->rootPath)->verify($other->sign(['productId' => 'back.a']));
    }

    public function testATamperedPayloadIsRefused(): void
    {
        $chain = new AppleTestChain();
        $parts = explode('.', $chain->sign(['productId' => 'back.a']));
        $parts[1] = Jwt::encode((string) json_encode(['productId' => 'back.b']));

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('signature');
        new AppleJwsVerifier($chain->rootPath)->verify(implode('.', $parts));
    }

    // Certificates without Apple's App Store marks are not ones that sign App Store data
    public function testCertificatesWithoutTheAppStoreMarksAreRefused(): void
    {
        $chain = new AppleTestChain(withMarks: false);

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('App Store ones');
        new AppleJwsVerifier($chain->rootPath)->verify($chain->sign(['productId' => 'back.a']));
    }

    public function testSomethingElseThanAJwsIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        new AppleJwsVerifier()->verify('not.a.jws');
    }

    // The root shipped with the bundle is Apple Root CA - G3, checked by its published fingerprint
    public function testTheShippedRootIsApplesG3(): void
    {
        $this->assertSame('63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179', openssl_x509_fingerprint((string) file_get_contents(__DIR__ . '/../../config/certificates/AppleRootCA-G3.pem'), 'sha256'));
    }
}
