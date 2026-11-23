<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

// A real query builder over a stubbed entity manager, its DQL, parameters and maximum read back after the query
trait QueryBuilderStubTrait
{
    private string $dql = '';

    /** @var array<string, mixed> */
    private array $parameters = [];

    private ?int $maxResults = null;

    // A real query builder whose DQL, parameters and maximum are read back, the rows given
    /**
     * @param class-string $entity
     * @param list<object> $rows
     */
    private function builder(string $entity, string $alias, array $rows): QueryBuilder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql) use ($rows): Query {
            $this->dql = $dql;
            $this->parameters = [];
            $query = $this->createStub(Query::class);
            $query->method('setParameters')->willReturnCallback(function (mixed $parameters) use (&$query): Query {
                foreach ($parameters as $parameter) {
                    $this->parameters[$parameter->getName()] = $parameter->getValue();
                }

                return $query;
            });
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnCallback(function (?int $maxResults) use (&$query): Query {
                $this->maxResults = $maxResults;

                return $query;
            });
            $query->method('getResult')->willReturn($rows);

            return $query;
        });

        return new QueryBuilder($entityManager)->select($alias)->from($entity, $alias);
    }
}
