<?php

declare(strict_types=1);

/*
 * DoctrineAuditBundle
 */

namespace Kricha\DoctrineAuditBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Kricha\DoctrineAuditBundle\AuditManager;
use Kricha\DoctrineAuditBundle\DBAL\Logging\AuditLogger;

#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class FlushListener
{
    public function __construct(private AuditManager $manager)
    {
        $this->manager = $manager;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $loggers = [];
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $this->manager->collectScheduledUpdates($uow, $em);
        $this->manager->collectScheduledInsertions($uow, $em);
        $this->manager->collectScheduledDeletions($uow, $em);
        $this->manager->collectScheduledCollectionDeletions($uow, $em);
        $this->manager->collectScheduledCollectionUpdates($uow, $em);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        $this->manager->processChanges($em);
        $this->manager->resetChangeset();
    }
}
