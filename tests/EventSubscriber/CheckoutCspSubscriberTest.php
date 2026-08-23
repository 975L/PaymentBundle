<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\EventSubscriber;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Contract\PaymentGatewayInterface;
use c975L\PaymentBundle\EventSubscriber\CheckoutCspSubscriber;
use c975L\PaymentBundle\Registry\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

// What the site's policy says about form submissions once the active provider has had its say: its checkout named, and nothing else of the policy moved
class CheckoutCspSubscriberTest extends TestCase
{
    public function testTheActiveGatewayCheckoutIsAddedToFormAction(): void
    {
        $response = $this->handle("default-src 'self'; form-action 'self'; img-src 'self' data:");

        $this->assertSame(
            "default-src 'self'; form-action 'self' checkout.stripe.com; img-src 'self' data:",
            $response->headers->get('Content-Security-Policy')
        );
    }

    // The reported policy and the two names older browsers read follow the enforced one, or a site running in report-only mode would be told of a violation it has no way of fixing
    public function testEveryPolicyHeaderIsCompleted(): void
    {
        $response = new Response();
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Security-Policy', 'X-Content-Security-Policy-Report-Only'] as $header) {
            $response->headers->set($header, "form-action 'self'");
        }

        $this->dispatch($response);

        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Security-Policy', 'X-Content-Security-Policy-Report-Only'] as $header) {
            $this->assertSame("form-action 'self' checkout.stripe.com", $response->headers->get($header));
        }
    }

    // "form-action" falls back on nothing, not even "default-src": a site that never wrote it restricts no form at all, so there is nothing to widen and its policy is left as it stands
    public function testAPolicyWithoutFormActionIsUntouched(): void
    {
        $response = $this->handle("default-src 'self'; script-src 'self'");

        $this->assertSame("default-src 'self'; script-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    // 'none' is only valid on its own, and a policy the browser throws away whole would lift the restriction on every other form of the site
    public function testNoneIsReplacedRatherThanAddedTo(): void
    {
        $response = $this->handle("form-action 'none'");

        $this->assertSame('form-action checkout.stripe.com', $response->headers->get('Content-Security-Policy'));
    }

    public function testADomainAlreadyNamedIsNotRepeated(): void
    {
        $response = $this->handle("form-action 'self' checkout.stripe.com");

        $this->assertSame("form-action 'self' checkout.stripe.com", $response->headers->get('Content-Security-Policy'));
    }

    // A site serving no policy of its own is not given one: the bundle completes what the site decided, it does not decide for it
    public function testAResponseWithoutAPolicyIsLeftAlone(): void
    {
        $response = new Response();

        $this->dispatch($response);

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    // A site whose providers all hold no key cannot charge at all, and has no checkout to authorize
    public function testWithNoProviderOfferedThePolicyIsUntouched(): void
    {
        $response = $this->handle("form-action 'self'", configured: false);

        $this->assertSame("form-action 'self'", $response->headers->get('Content-Security-Policy'));
    }

    // The customer picks at the basket, so every provider offered is named - a host left out takes the order then loses them on the way to paying
    public function testEveryOfferedProviderIsNamed(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "form-action 'self'");

        $stripe = $this->gateway('stripe', ['checkout.stripe.com']);
        $revolut = $this->gateway('revolut', ['checkout.revolut.com']);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'payment-gateway' === $slug ? 'stripe' : null);

        new CheckoutCspSubscriber(new PaymentGatewayRegistry([$stripe, $revolut], $configService))->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        $this->assertSame("form-action 'self' checkout.stripe.com checkout.revolut.com", $response->headers->get('Content-Security-Policy'));
    }

    // The policy is only ever written on the response the browser gets, and a sub-request's own carries none the site sends
    public function testASubRequestIsIgnored(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "form-action 'self'");

        $this->subscriber()->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            $response
        ));

        $this->assertSame("form-action 'self'", $response->headers->get('Content-Security-Policy'));
    }

    private function handle(string $policy, string $activeSlug = 'stripe', bool $configured = true): Response
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', $policy);

        $this->dispatch($response, $activeSlug, $configured);

        return $response;
    }

    private function dispatch(Response $response, string $activeSlug = 'stripe', bool $configured = true): void
    {
        $this->subscriber($activeSlug, $configured)->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));
    }

    private function subscriber(string $activeSlug = 'stripe', bool $configured = true): CheckoutCspSubscriber
    {
        $gateway = $this->gateway('stripe', ['checkout.stripe.com'], $configured);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug) => 'payment-gateway' === $slug ? $activeSlug : null);

        return new CheckoutCspSubscriber(new PaymentGatewayRegistry([$gateway], $configService));
    }

    private function gateway(string $slug, array $domains, bool $configured = true): PaymentGatewayInterface
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getSlug')->willReturn($slug);
        $gateway->method('isConfigured')->willReturn($configured);
        $gateway->method('getCheckoutDomains')->willReturn($domains);

        return $gateway;
    }
}
