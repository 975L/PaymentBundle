<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Store;

use c975L\PaymentBundle\Exception\InvalidNotificationException;

// Reads what Apple signs - a transaction, a server notification - only once its certificate chain leads to Apple's own root and the signature checks out against it. A notification reaches a public url, so this is the one thing standing between anybody and a refund taking a back away; Apple publishes no PHP library for it, hence these few checks, the ones its own libraries make
class AppleJwsVerifier
{
    // The marks Apple puts on the two certificates below its root, the leaf signing App Store data and the intermediate issuing it
    private const string LEAF_OID = '1.2.840.113635.100.6.11.1';
    private const string INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    public function __construct(
        private readonly string $rootCertificate = __DIR__ . '/../../config/certificates/AppleRootCA-G3.pem',
    ) {
    }

    // The payload of a JWS signed by Apple
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidNotificationException when the chain or the signature does not check out
     */
    public function verify(string $jws): array
    {
        $parts = explode('.', $jws);
        $header = 3 === \count($parts) ? json_decode(Jwt::decode($parts[0]), true) : null;
        if (!\is_array($header) || 'ES256' !== ($header['alg'] ?? null) || !\is_array($header['x5c'] ?? null) || 3 !== \count($header['x5c'])) {
            throw new InvalidNotificationException('Not a JWS signed by Apple');
        }

        [$leaf, $intermediate, $root] = array_map(self::pem(...), $header['x5c']);
        $this->verifyChain($leaf, $intermediate, $root);
        if (1 !== openssl_verify($parts[0] . '.' . $parts[1], Jwt::rawToDer(Jwt::decode($parts[2])), $leaf, \OPENSSL_ALGO_SHA256)) {
            throw new InvalidNotificationException('The signature of the JWS does not check out');
        }

        $payload = json_decode(Jwt::decode($parts[1]), true);
        if (!\is_array($payload)) {
            throw new InvalidNotificationException('The payload of the JWS cannot be read');
        }

        return $payload;
    }

    // The root carried must be Apple's own, byte for byte, and each certificate signed by the one above it
    private function verifyChain(string $leaf, string $intermediate, string $root): void
    {
        $expected = file_get_contents($this->rootCertificate);
        if (false === $expected || openssl_x509_fingerprint($root, 'sha256') !== openssl_x509_fingerprint($expected, 'sha256')) {
            throw new InvalidNotificationException('The JWS does not lead to Apple\'s root certificate');
        }
        if (1 !== openssl_x509_verify($intermediate, $root) || 1 !== openssl_x509_verify($leaf, $intermediate)) {
            throw new InvalidNotificationException('The certificate chain of the JWS is broken');
        }
        if (!self::hasExtension($leaf, self::LEAF_OID) || !self::hasExtension($intermediate, self::INTERMEDIATE_OID)) {
            throw new InvalidNotificationException('The certificates of the JWS are not Apple\'s App Store ones');
        }
        if (!self::isCurrent($leaf) || !self::isCurrent($intermediate)) {
            throw new InvalidNotificationException('A certificate of the JWS is expired or not valid yet');
        }
    }

    // Whether the certificate is within its validity dates
    private static function isCurrent(string $certificate): bool
    {
        $parsed = openssl_x509_parse($certificate);

        return \is_array($parsed) && $parsed['validFrom_time_t'] <= time() && time() <= $parsed['validTo_time_t'];
    }

    // Whether the certificate carries Apple's mark of the given OID
    private static function hasExtension(string $certificate, string $oid): bool
    {
        $parsed = openssl_x509_parse($certificate);

        return \is_array($parsed) && \array_key_exists($oid, $parsed['extensions'] ?? []);
    }

    // A certificate of the x5c header, base64 DER, wrapped as the PEM openssl reads
    private static function pem(mixed $der): string
    {
        if (!\is_string($der)) {
            throw new InvalidNotificationException('A certificate of the JWS cannot be read');
        }

        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($der, 64, "\n") . "-----END CERTIFICATE-----\n";
    }
}
