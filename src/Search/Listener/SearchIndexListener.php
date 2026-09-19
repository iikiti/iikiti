<?php

namespace iikiti\CMS\Search\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Search\Service\IndexManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps search index tables in sync when {@see DbObject} entities are
 * written or removed.
 *
 * Affected objects are collected during onFlush (when the database write
 * has not yet committed) and processed in postFlush — after the transaction
 * is safe so the index query can read the persisted entities.
 *
 * Failures are logged but never thrown, so search indexing problems cannot
 * break the primary save/delete operation.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class SearchIndexListener
{
	/** @var list<DbObject> */
	private array $toIndex = [];

	/** @var list<DbObject> */
	private array $toRemove = [];

	public function __construct(
		private readonly IndexManager $indexManager,
		private readonly LoggerInterface $logger = new NullLogger(),
	) {
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		if (!$this->indexManager->isAutoIndex()) {
			return;
		}

		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		foreach ($uow->getScheduledEntityInsertions() as $entity) {
			if ($entity instanceof DbObject) {
				$this->toIndex[] = $entity;
			}
		}

		foreach ($uow->getScheduledEntityUpdates() as $entity) {
			if ($entity instanceof DbObject) {
				$this->toIndex[] = $entity;
			}
		}

		foreach ($uow->getScheduledEntityDeletions() as $entity) {
			if ($entity instanceof DbObject) {
				$this->toRemove[] = $entity;
			}
		}
	}

	public function postFlush(PostFlushEventArgs $args): void
	{
		$this->processPending();
	}

	/**
	 * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
	 */
	public function postRemove(LifecycleEventArgs $args): void
	{
		$entity = $args->getObject();
		if ($entity instanceof DbObject && $this->indexManager->isAutoIndex()) {
			try {
				$this->indexManager->removeFromIndexes($entity);
			} catch (\Throwable $e) {
				$this->logger->error('Search index removal failed: '.$e->getMessage(), [
					'object_id' => $entity->getId(),
					'object_type' => $entity->getType(),
				]);
			}
		}
	}

	private function processPending(): void
	{
		foreach ($this->toIndex as $object) {
			try {
				$this->indexManager->reindexObject($object, $object->getType());
			} catch (\Throwable $e) {
				$this->logger->error('Search index update failed: '.$e->getMessage(), [
					'object_id' => $object->getId(),
					'object_type' => $object->getType(),
				]);
			}
		}

		foreach ($this->toRemove as $object) {
			try {
				$this->indexManager->removeFromIndexes($object);
			} catch (\Throwable $e) {
				$this->logger->error('Search index removal failed: '.$e->getMessage(), [
					'object_id' => $object->getId(),
					'object_type' => $object->getType(),
				]);
			}
		}

		$this->toIndex = [];
		$this->toRemove = [];
	}
}
