<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Store;

use c975L\PaymentBundle\Store\Jwt;

// A certificate chain shaped like Apple's - a root, an intermediate and a leaf carrying Apple's App Store marks - generated on the fly, so the verifier is tested on real signatures without Apple's private keys
final class AppleTestChain
{
    public readonly string $rootPath;
    private string $root = '';
    private string $intermediate = '';
    private string $leaf = '';
    private \OpenSSLAsymmetricKey $leafKey;

    public function __construct(bool $withMarks = true)
    {
        $config = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\nbasicConstraints = critical,CA:true\n[v3_int]\nbasicConstraints = critical,CA:true\n" . ($withMarks ? "1.2.840.113635.100.6.2.1 = ASN1:NULL\n" : '') . "[v3_leaf]\n" . ($withMarks ? "1.2.840.113635.100.6.11.1 = ASN1:NULL\n" : "basicConstraints = CA:false\n"));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

        $rootKey = openssl_pkey_new($options);
        $rootCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Root'], $rootKey, $options), null, $rootKey, 1, $options + ['x509_extensions' => 'v3_ca'], 1);
        $intermediateKey = openssl_pkey_new($options);
        $intermediateCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Intermediate'], $intermediateKey, $options), $rootCert, $rootKey, 1, $options + ['x509_extensions' => 'v3_int'], 2);
        $this->leafKey = openssl_pkey_new($options);
        $leafCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Leaf'], $this->leafKey, $options), $intermediateCert, $intermediateKey, 1, $options + ['x509_extensions' => 'v3_leaf'], 3);
        unlink($config);

        openssl_x509_export($rootCert, $this->root);
        openssl_x509_export($intermediateCert, $this->intermediate);
        openssl_x509_export($leafCert, $this->leaf);
        $this->rootPath = (string) tempnam(sys_get_temp_dir(), 'root');
        file_put_contents($this->rootPath, $this->root);
    }

    public function __destruct()
    {
        @unlink($this->rootPath);
    }

    // A JWS signed by the leaf, its chain in the header as Apple sends it
    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        openssl_pkey_export($this->leafKey, $key);
        $x5c = array_map(static fn (string $pem): string => preg_replace('/-----[A-Z ]+-----|\s/', '', $pem), [$this->leaf, $this->intermediate, $this->root]);

        return Jwt::sign(['x5c' => $x5c], $payload, $key);
    }
}
