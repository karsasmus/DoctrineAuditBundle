<?php

declare(strict_types=1);

/*
 * DoctrineAuditBundle
 */

namespace Kricha\DoctrineAuditBundle\Reader;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Kricha\DoctrineAuditBundle\AuditConfiguration;
use Pagerfanta\Doctrine\DBAL\SingleTableQueryAdapter;
use Pagerfanta\Pagerfanta;

class AuditReader
{
    public const PAGE_SIZE = 50;

    private ?string $filter = null;

    private ?string $changerRoute = null;

    public function __construct(
        private AuditConfiguration $configuration,
        private EntityManagerInterface $entityManager,
        array $bundleConfig)
    {
        $this->changerRoute  = $bundleConfig['changer_route'] ?? null;
    }

    public function getChangerRoute(): ?string
    {
        return $this->changerRoute;
    }

    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Returns current filter.
     */
    public function getFilter(): ?string
    {
        return $this->filter;
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     */
    public function getEntities(): array
    {
        $metadataDriver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities       = [];
        if (null !== $metadataDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }
        $audited = [];
        foreach ($entities as $entity) {
            if ($this->configuration->isAuditable($entity)) {
                $audited[$entity] = [
                    'table'        => $this->getEntityTableName($entity),
                    'audits_count' => $this->getAuditsCount($entity),
                ];
            }
        }
        \ksort($audited);

        return $audited;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string|null $id
     * @param int|null $page
     * @param int|null $pageSize
     * @return Generator
     * @throws Exception
     */
    public function getAudits(
        object|string $entity,
        int|string $id = null,
        ?int $page = null,
        ?int $pageSize = null
    ): Generator
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, $page, $pageSize);

        return $queryBuilder->executeQuery()->iterateAssociative();
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param null|int      $page
     * @param null|int      $pageSize
     */
    public function getAuditsPager(object|string $entity, int|string $id = null, int $page = 1, int $pageSize = self::PAGE_SIZE): Pagerfanta
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $adapter = new SingleTableQueryAdapter($queryBuilder, 'at.id');

        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta
            ->setMaxPerPage($pageSize)
            ->setCurrentPage($page)
        ;

        return $pagerfanta;
    }

    /**
     * Returns the amount of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getAuditsCount(object|string $entity, int|string $id = null): int
    {
        $this->entityManager->createQueryBuilder();
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $result = $queryBuilder
            ->executeQuery()->fetchAssociative();

        return (int) count($result);
    }

    /**
     * @param object|string $entity
     * @param int|string    $id
     */
    public function getAudit(object|string $entity, int|string $id): Generator
    {
        $connection = $this->entityManager->getConnection();
        $schema     = $this->entityManager->getClassMetadata(\is_string($entity) ? $entity : \get_class($entity))->getSchemaName();

        $auditTable = \implode('', [
            null === $schema ? '' : $schema.'.',
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName(\is_string($entity) ? $entity : \get_class($entity)),
            $this->configuration->getTableSuffix(),
        ]);

        /**
         * @var \Doctrine\DBAL\Query\QueryBuilder
         */
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($auditTable)
            ->where('id = :id')
            ->setParameter('id', $id)
        ;

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter)
            ;
        }


        return $queryBuilder->executeQuery()->iterateAssociative();
    }

    /**
     * Returns the table name of $entity.
     *
     * @param object|string $entity
     */
    public function getEntityTableName(object|string $entity): string
    {
        return $this
            ->entityManager
            ->getClassMetadata($entity)
            ->getTableName()
        ;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param int           $page
     * @param int           $pageSize
     */
    private function getAuditsQueryBuilder(object|string $entity, int|string $id = null, ?int $page = null, ?int $pageSize = null): QueryBuilder
    {
        if (null !== $page && $page < 1) {
            throw new \InvalidArgumentException('$page must be greater or equal than 1.');
        }

        if (null !== $pageSize && $pageSize < 1) {
            throw new \InvalidArgumentException('$pageSize must be greater or equal than 1.');
        }

        $connection = $this->entityManager->getConnection();
        $schema     = $this->entityManager->getClassMetadata(\is_string($entity) ? $entity : \get_class($entity))->getSchemaName();

        $auditTable = \implode('', [
            null === $schema ? '' : $schema.'.',
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName(\is_string($entity) ? $entity : \get_class($entity)),
            $this->configuration->getTableSuffix(),
        ]);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($auditTable, 'at')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
        ;

        if (null !== $pageSize) {
            $queryBuilder
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
            ;
        }

        if (null !== $id) {
            $queryBuilder
                ->andWhere('object_id = :object_id')
                ->setParameter('object_id', $id)
            ;
        }

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter)
            ;
        }

        return $queryBuilder;
    }
}
