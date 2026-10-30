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
use c975L\PaymentBundle\Store\Jwt;
use PHPUnit\Framework\TestCase;

// The JOSE signatures written and read without a library
class JwtTest extends TestCase
{
    public function testAnEcKeySignsInEs256VerifiableWithItsPublicKey(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        [$header, $claims, $signature] = explode('.', Jwt::sign(['kid' => 'K1'], ['iss' => 'me'], $pem));

        $this->assertSame(['kid' => 'K1', 'alg' => 'ES256', 'typ' => 'JWT'], json_decode(Jwt::decode($header), true));
        $this->assertSame(64, \strlen(Jwt::decode($signature)));
        $this->assertSame(1, openssl_verify($header . '.' . $claims, Jwt::rawToDer(Jwt::decode($signature)), openssl_pkey_get_details($key)['key'], \OPENSSL_ALGO_SHA256));
    }

    public function testAnRsaKeySignsInRs256(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        [$header, $claims, $signature] = explode('.', Jwt::sign([], ['iss' => 'me'], $pem));

        $this->assertSame('RS256', json_decode(Jwt::decode($header), true)['alg']);
        $this->assertSame(1, openssl_verify($header . '.' . $claims, Jwt::decode($signature), openssl_pkey_get_details($key)['key'], \OPENSSL_ALGO_SHA256));
    }

    // r or s starting with a high bit takes a leading zero in DER, and loses it again in the raw form
    public function testRawAndDerSignaturesConvertBothWays(): void
    {
        $raw = str_repeat("\x80", 32) . str_repeat("\x01", 32);

        $this->assertSame($raw, Jwt::derToRaw(Jwt::rawToDer($raw)));
    }

    // An empty or truncated signature, posted by anybody on the notification url, is refused rather than crashing str_split()
    public function testAnEmptySignatureIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        Jwt::rawToDer('');
    }

    public function testATruncatedSignatureIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);

        Jwt::rawToDer(str_repeat("\x01", 63));
    }
}
