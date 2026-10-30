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

// The few lines of JWT the two stores need - Apple's ES256 tokens and the JWS it signs, Google's RS256 service account assertion - written against openssl rather than pulling a library for them
final class Jwt
{
    // Signs claims with a PEM private key, ES256 for an EC key, RS256 for an RSA one
    /** @param array<string, mixed> $header @param array<string, mixed> $claims */
    public static function sign(array $header, array $claims, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        if (false === $key) {
            throw new \InvalidArgumentException('The private key cannot be read');
        }
        $isEc = \OPENSSL_KEYTYPE_EC === openssl_pkey_get_details($key)['type'];
        $input = self::encode((string) json_encode($header + ['alg' => $isEc ? 'ES256' : 'RS256', 'typ' => 'JWT'])) . '.' . self::encode((string) json_encode($claims));
        openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256);

        return $input . '.' . self::encode($isEc ? self::derToRaw($signature) : $signature);
    }

    // Base64url without padding, as every part of a JWT is written
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // The reverse, an empty string for what is not base64url
    public static function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    // An ECDSA signature as JOSE writes it, r and s side by side on 32 bytes each, from the DER sequence openssl gives
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (\ord($der[1]) > 0x80 ? \ord($der[1]) - 0x80 : 0);
        $raw = '';
        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\x00"), 32, "\x00", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    // The reverse, the DER sequence openssl verifies from the raw JOSE signature - anything but ES256's 64 bytes being refused, as it comes from a public url
    public static function rawToDer(string $raw): string
    {
        if (64 !== \strlen($raw)) {
            throw new InvalidNotificationException('The signature is not an ES256 one');
        }

        $sequence = '';
        foreach (str_split($raw, 32) as $integer) {
            $integer = ltrim($integer, "\x00");
            if ('' === $integer || \ord($integer[0]) >= 0x80) {
                $integer = "\x00" . $integer;
            }
            $sequence .= "\x02" . \chr(\strlen($integer)) . $integer;
        }

        return "\x30" . \chr(\strlen($sequence)) . $sequence;
    }
}
