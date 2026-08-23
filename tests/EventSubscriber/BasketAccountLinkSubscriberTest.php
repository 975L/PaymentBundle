<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\EventSubscriber;

use c975L\PaymentBundle\EventSubscriber\BasketAccountLinkSubscriber;
use c975L\PaymentBundle\Repository\BasketRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class BasketAccountLinkSubscriberTest extends TestCase
{
    private function createEvent(UserInterface $user): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new Passport(new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user), new class implements \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CredentialsInterface {
                public function isResolved(): bool
                {
                    return true;
                }
            }),
            $this->createStub(TokenInterface::class),
            new Request(),
            null,
            'main',
        );
    }

    private function createRepository(?array &$calls = null): BasketRepository
    {
        $repository = $this->createStub(BasketRepository::class);
        $repository->method('attachOrphansTo')->willReturnCallback(static function (UserInterface $user, string $email) use (&$calls): int {
            $calls[] = [$user->getUserIdentifier(), $email];

            return 2;
        });

        return $repository;
    }

    // What the invitation on an order confirmation is worth following for: the orders placed as a guest become theirs
    public function testAnEnabledAccountIsGivenTheOrdersLeftUnderItsAddress(): void
    {
        $calls = [];
        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'buyer@example.test';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        new BasketAccountLinkSubscriber($this->createRepository($calls))->onLoginSuccess($this->createEvent($user));

        $this->assertSame([['buyer@example.test', 'buyer@example.test']], $calls);
    }

    // An account is only ever enabled once its address was proved - by the confirmation email, or by the provider that vouched for it. Matching on an unproved one would hand a stranger's orders, delivery address included, to whoever registered with their email
    public function testAnAccountThatNeverProvedItsAddressIsGivenNothing(): void
    {
        $calls = [];
        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'buyer@example.test';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function isEnabled(): bool
            {
                return false;
            }
        };

        new BasketAccountLinkSubscriber($this->createRepository($calls))->onLoginSuccess($this->createEvent($user));

        $this->assertSame([], $calls);
    }

    // An entity declaring no such state is read as proved: nothing refuses its login either, so there is no door this would be closing
    public function testAnEntityWithoutThatStateIsTreatedAsProved(): void
    {
        $calls = [];
        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'buyer@example.test';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }
        };

        new BasketAccountLinkSubscriber($this->createRepository($calls))->onLoginSuccess($this->createEvent($user));

        $this->assertCount(1, $calls);
    }
}
