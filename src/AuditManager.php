<?php

declare(strict_types=1);

/*
 * DoctrineAuditBundle
 */

namespace Kricha\DoctrineAuditBundle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\PersistentCollection;

class AuditManager
{
    public const INSERT     = 'INS';
    public const UPDATE     = 'UPD';
    public const DELETE     = 'DEL';
    public const ASSOCIATE  = 'CASC';
    public const DISSOCIATE = 'CDSC';

    private $auditConfiguration;

    private $changes;

    public function __construct(AuditConfiguration $auditConfiguration)
    {
        $this->auditConfiguration = $auditConfiguration;
    }

    public function resetChangeset(): void
    {
        $this->changes = [];
    }

    public function collectScheduledInsertions(UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->auditConfiguration->isAudited($entity)) {
                $changeSet       = $uow->getEntityChangeSet($entity);
                $diff            = $this->diff($em, $entity, $changeSet);
                $this->changes[] = [
                    'action' => self::INSERT,
                    'data'   => [
                        $entity,
                        $diff,
                    ],
                ];
            }
        }
    }

    public function collectScheduledUpdates(UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->auditConfiguration->isAudited($entity)) {
                $changeSet       = $uow->getEntityChangeSet($entity);
                $diff            = $this->diff($em, $entity, $changeSet);
                $this->changes[] = [
                    'action' => self::UPDATE,
                    'data'   => [
                        $entity,
                        $diff,
                    ],
                ];
            }
        }
    }

    public function collectScheduledDeletions(UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->auditConfiguration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $id                            = $this->id($em, $entity);
                $changes                       = [];
                $entityClassName               = \get_class($entity);

                foreach ((array) $entity as $fieldName => $value) {
                    $realFieldName = \str_replace(["\x00*\x00", "\x00${entityClassName}\x00"], '', $fieldName);
                    if (\is_object($value)) {
                        if (\method_exists($value, '__toString')) {
                            $realValue = (string) $value;
                        } elseif ($value instanceof \DateTime) {
                            $realValue = $value->format('r');
                        } elseif ($value instanceof PersistentCollection) {
                            continue;
                        } else {
                            $realValue = \get_class($value).'#'.$this->id($em, $value);
                        }
                    } else {
                        $realValue = $value;
                    }
                    $changes[$realFieldName]['old'] = $realValue;
                    $changes[$realFieldName]['new'] = null;
                }

                $this->changes[]               = [
                    'action' => self::DELETE,
                    'data'   => [
                        'entity' => $entity,
                        'diff'   => $changes,
                        'id'     => $id,
                    ],
                ];
            }
        }
    }

    public function collectScheduledCollectionUpdates(UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->auditConfiguration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->auditConfiguration->isAudited($entity)) {
                        $diff = [
                            'source' => $this->summarize($em, $collection->getOwner()),
                            'target' => $this->summarize($em, $entity),
                        ];
                        if (isset($mapping['joinTable']['name'])) {
                            $data['diff']['table'] = $mapping['joinTable']['name'];
                        }
                        $this->changes[]               = [
                            'action' => self::ASSOCIATE,
                            'data'   => [
                                $collection->getOwner(),
                                $diff,
                            ],
                        ];
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->auditConfiguration->isAudited($entity)) {
                        $diff = [
                            'source' => $this->summarize($em, $collection->getOwner()),
                            'target' => $this->summarize($em, $entity),
                        ];
                        if (isset($mapping['joinTable']['name'])) {
                            $data['diff']['table'] = $mapping['joinTable']['name'];
                        }
                        $this->changes[]               = [
                            'action' => self::DISSOCIATE,
                            'data'   => [
                                $collection->getOwner(),
                                $diff,
                            ],
                        ];
                    }
                }
            }
        }
    }

    public function collectScheduledCollectionDeletions(UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->auditConfiguration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if (!$this->auditConfiguration->isAudited($entity)) {
                        continue;
                    }
                    $diff = [
                        'source' => $this->summarize($em, $collection->getOwner()),
                        'target' => $this->summarize($em, $entity),
                    ];
                    if (isset($mapping['joinTable']['name'])) {
                        $data['diff']['table'] = $mapping['joinTable']['name'];
                    }
                    $this->changes[]               = [
                        'action' => self::DISSOCIATE,
                        'data'   => [
                            $collection->getOwner(),
                            $diff,
                        ],
                    ];
                }
            }
        }
    }

    public function diff(EntityManager $em, $entity, array $ch): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => [$old, $new]) {
            $o = null;
            $n = null;
            if ($this->auditConfiguration->isAuditedField($entity, $fieldName)) {
                if (!isset($meta->embeddedClasses[$fieldName]) && $meta->hasField($fieldName)) {
                    $mapping = $meta->fieldMappings[$fieldName];
                    $type    = Type::getType($mapping['type']);
                    $o       = $this->value($em, $type, $old, $mapping);
                    $n       = $this->value($em, $type, $new, $mapping);
                }
                if ($meta->hasAssociation($fieldName) && $meta->isSingleValuedAssociation($fieldName)
                ) {
                    $o = $this->summarize($em, $old);
                    $n = $this->summarize($em, $new);
                }
            }

            if ($o !== $n) {
                $diff[$fieldName] = [
                    'old' => $o,
                    'new' => $n,
                ];
            }
        }

        return $diff;
    }

    public function summarize(EntityManager $em, $entity = null, $id = null): ?array
    {
        if (null === $entity) {
            return null;
        }
        $em->getUnitOfWork()->initializeObject($entity); // ensure that proxies are initialized
        $meta   = $em->getClassMetadata(Helper::getRealClass($entity));
        $pkName = $meta->getSingleIdentifierFieldName();

        $pkValue = $id ?? $this->id($em, $entity);
        if (\method_exists($entity, '__toString')) {
            $label = (string) $entity;
        } else {
            $label = \get_class($entity).'#'.$pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->getTableName(),
            $pkName => $pkValue,
        ];
    }

    public function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $pk   = $meta->getSingleIdentifierFieldName();
        if (isset($meta->fieldMappings[$pk])) {
            $type = Type::getType($meta->fieldMappings[$pk]['type']);

            return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
        }
        // Primary key is not part of fieldMapping
        // @see https://github.com/DamienHarper/DoctrineAuditBundle/issues/40
        // @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
        // We try to get it from associationMapping (will throw a MappingException if not available)
        $targetEntity = $meta->getReflectionProperty($pk)->getValue($entity);
        $mapping      = $meta->getAssociationMapping($pk);
        $meta         = $em->getClassMetadata($mapping['targetEntity']);
        $pk           = $meta->getSingleIdentifierFieldName();
        $type         = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($targetEntity));
    }

    public function insert(EntityManager $em, $entity, array $diff): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));

        return $this->audit(
            $em,
            [
                'action'  => self::INSERT,
                'changer' => $this->auditConfiguration->getCurrentUsername(),
                'diff'    => $diff,
                'table'   => $meta->getTableName(),
                'schema'  => $meta->getSchemaName(),
                'id'      => $this->id($em, $entity),
            ]
        );
    }

    public function update(EntityManager $em, $entity, array $diff): array
    {
        if (!$diff) {
            return []; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(\get_class($entity));

        return $this->audit(
            $em,
            [
                'action'  => self::UPDATE,
                'changer' => $this->auditConfiguration->getCurrentUsername(),
                'diff'    => $diff,
                'table'   => $meta->getTableName(),
                'schema'  => $meta->getSchemaName(),
                'id'      => $this->id($em, $entity),
            ]
        );
    }

    public function remove(EntityManager $em, $entity, $diff, $id): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));

        return $this->audit(
            $em,
            [
                'action'  => self::DELETE,
                'changer' => $this->auditConfiguration->getCurrentUsername(),
                'diff'    => $diff,
                'table'   => $meta->getTableName(),
                'schema'  => $meta->getSchemaName(),
                'id'      => $id,
            ]
        );
    }

    public function processChanges(EntityManager $em): void
    {
        if ($this->changes) {
            $queries = [];
            foreach ($this->changes as $entityChanges) {
                $action = $entityChanges['action'];
                $data   = $entityChanges['data'];
                switch ($action) {
                    case self::INSERT:
                        $query = $this->insert($em, $data[0], $data[1]);
                        break;
                    case self::UPDATE:
                        $query = $this->update($em, $data[0], $data[1]);
                        break;
                    case self::DELETE:
                        $query = $this->remove($em, $data['entity'], $data['diff'], $data['id']);
                        break;
                    case self::ASSOCIATE:
                    case self::DISSOCIATE:
                        $query = $this->toggleAssociation($action, $em, $data[0], $data[1]);
                        break;
                }
                if ($query) {
                    $queries[] = $query;
                }
            }

            $em->getConnection()->transactional(function (Connection $connection) use ($queries): void {
                foreach ($queries as $query) {
                    $stmt = $connection->prepare($query[0]);
                    $stmt->executeStatement($query[1]);
                }
            });
        }
    }

    public function getAuditConfiguration(): AuditConfiguration
    {
        return $this->auditConfiguration;
    }

    private function toggleAssociation(string $type, EntityManager $em, $entity, array $diff): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $data = [
            'action'  => $type,
            'changer' => $this->auditConfiguration->getCurrentUsername(),
            'diff'    => $diff,
            'table'   => $meta->getTableName(),
            'schema'  => $meta->getSchemaName(),
            'id'      => $this->id($em, $entity),
        ];

        return $this->audit($em, $data);
    }

    private function audit(EntityManager $em, array $data): array
    {
        $schema     = $data['schema'] ? $data['schema'].'.' : '';
        $auditTable = $schema.$this->auditConfiguration->getTablePrefix(
            ).$data['table'].$this->auditConfiguration->getTableSuffix();
        $fields = [
            'type'       => ':type',
            'object_id'  => ':object_id',
            'diff'       => ':diff',
            'changer'    => ':changer',
            'created_at' => ':created_at',
        ];
        $query = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $auditTable,
            \implode(', ', \array_keys($fields)),
            \implode(', ', \array_values($fields))
        );

        $dt     = new \DateTime('now');
        $params = [
            'type'       => $data['action'],
            'object_id'  => (string) $data['id'],
            'diff'       => \json_encode($data['diff']),
            'changer'    => $data['changer'],
            'created_at' => $dt->format('Y-m-d H:i:s'),
        ];

        return [$query, $params];
    }

    private function value(EntityManager $em, Type $type, $value, $mapping = [])
    {
        if (null === $value) {
            return null;
        }
        $platform = $em->getConnection()->getDatabasePlatform();
        switch ($type->getName()) {
            case Types::DECIMAL:
                if ($mapping) {
                    $convertedValue = \number_format((float) $value, $mapping['scale'], '.', '');
                    break;
                }
            // no break
            case Types::BIGINT:
                $convertedValue = (string) $value;
                break;
            case Types::INTEGER:
            case Types::SMALLINT:
                $convertedValue = (int) $value;
                break;
            case Types::FLOAT:
            case Types::BOOLEAN:
                $convertedValue = $type->convertToPHPValue($value, $platform);
                break;
            case Types::BLOB:
                if (\is_resource($value)) {
                    $convertedValue = base64_encode(stream_get_contents($value));
                    rewind($value);
                } else {
                    $convertedValue = base64_encode($value);
                }
                break;
            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }
}
