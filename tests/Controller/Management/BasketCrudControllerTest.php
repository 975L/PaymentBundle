<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\TableExporter;
use c975L\PaymentBundle\Controller\Management\BasketCrudController;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BasketCrudControllerTest extends TestCase
{
    // The exported rows must never carry the token that opens a customer's order-tracking url (see BasketController)
    public function testFetchExportRowsDropsTheSecurityToken(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'number' => 'AB12', 'security_token' => 'a1b2c3d4e5f60718', 'email' => 'buyer@example.com'],
        ]);

        // The column is resolved through the mapping, its name depending on the naming strategy the site configures
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getColumnName')->willReturn('security_token');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $controller = new BasketCrudController(
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->createStub(ConfigServiceInterface::class),
            $entityManager,
            $this->createStub(TableExporter::class),
            $this->createStub(TranslatorInterface::class),
        );

        $rows = new \ReflectionMethod($controller, 'fetchExportRows')->invoke($controller);

        $this->assertSame([['id' => 1, 'number' => 'AB12', 'email' => 'buyer@example.com']], $rows);
    }
}
