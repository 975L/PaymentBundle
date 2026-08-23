<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\Contract\VerifiableGatewayInterface;
use c975L\PaymentBundle\Management\GatewayHealthCheckProvider;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\TestCase;

// The check PaymentAlertProvider cannot make: a revoked or mistyped key reads from the config exactly like a working one
class GatewayHealthCheckProviderTest extends TestCase
{
    // A site that sells nothing is not a broken site - PaymentAlertProvider is the one that speaks up about a gateway named without its keys
    public function testNothingIsReportedWhenNoProviderIsOffered(): void
    {
        $this->assertSame([], $this->provider(null)->runChecks());
    }

    public function testAuthenticatingKeysAreReportedOk(): void
    {
        $checks = $this->provider($this->verifiableGateway(null))->runChecks();

        $this->assertCount(1, $checks);
        $this->assertSame(HealthCheckResult::STATUS_OK, $checks[0]['status']);
        $this->assertSame('stripe', $checks[0]['details']['gateway']);
    }

    public function testRejectedKeysAreReportedWithTheProviderReason(): void
    {
        $checks = $this->provider($this->verifiableGateway('Invalid API Key provided'))->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $checks[0]['status']);
        $this->assertSame('Invalid API Key provided', $checks[0]['summary']);
    }

    // A gateway offering no such call stays valid - the row says so rather than disappearing, so the dashboard shows why nothing is known
    public function testAGatewayThatCannotBeAskedIsReportedAsSkipped(): void
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn('revolut');

        $checks = $this->provider($gateway)->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_SKIPPED, $checks[0]['status']);
    }

    // The mode is on the row: the same site holds two pairs of keys, and only one of them is being checked
    public function testTheRowNamesTheModeBeingChecked(): void
    {
        $checks = $this->provider($this->verifiableGateway(null), testMode: true)->runChecks();

        $this->assertSame('stripe (test)', $checks[0]['label']);
        $this->assertSame('test', $checks[0]['details']['mode']);
    }

    private function verifiableGateway(?string $error): PaymentGatewayInterface
    {
        $gateway = $this->createStubForIntersectionOfInterfaces([PaymentGatewayInterface::class, VerifiableGatewayInterface::class]);
        $gateway->method('getSlug')->willReturn('stripe');
        $gateway->method('verifyCredentials')->willReturn($error);

        return $gateway;
    }

    private function provider(?PaymentGatewayInterface $gateway, bool $testMode = false): GatewayHealthCheckProvider
    {
        $registry = $this->createStub(PaymentGatewayRegistry::class);
        $registry->method('getOffered')->willReturn(null === $gateway ? [] : [$gateway->getSlug() => $gateway]);

        $paymentTestMode = $this->createStub(PaymentTestModeInterface::class);
        $paymentTestMode->method('isEnabled')->willReturn($testMode);

        return new GatewayHealthCheckProvider($registry, $paymentTestMode);
    }
}
