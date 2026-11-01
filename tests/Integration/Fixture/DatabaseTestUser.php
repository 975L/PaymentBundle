<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Integration\Fixture;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

// What the entities' UserInterface relations resolve to on the test database, a site's own user class standing there in production
#[ORM\Entity]
#[ORM\Table(name: 'test_user')]
class DatabaseTestUser implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function getRoles(): array
    {
        return [];
    }

    public function getUserIdentifier(): string
    {
        return 'test';
    }
}
