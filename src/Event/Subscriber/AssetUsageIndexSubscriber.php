<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexer;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\ObjectProperty;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the `digital_asset_usages` projection synchronized whenever templates,
 * pages, sites, applications, or their tracked JSON properties (`blocks*`,
 * `dynamic_blocks*`, `settings*`, `configuration`) are persisted, updated, or deleted.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::onClear)]
final class AssetUsageIndexSubscriber implements ResetInterface
{
	/** @var array<int, DbObject> */
	private array $pendingReindexEntities = [];

	/** @var array<string, array{0: string, 1: int|string}> */
	private array $pendingDeletes = [];

	private bool $processing = false;

	public function __construct(
		private readonly AssetUsageIndexer $indexer,
	) {
	}

	public function onClear(): void
	{
		$this->reset();
	}

	public function reset(): void
	{
		$this->pendingReindexEntities = [];
		$this->pendingDeletes = [];
		$this->processing = false;
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		if ($this->processing) {
			return;
		}

		$uow = $args->getObjectManager()->getUnitOfWork();

		$written = array_merge(
			$uow->getScheduledEntityInsertions(),
			$uow->getScheduledEntityUpdates(),
		);

		foreach ($written as $entity) {
			if ($entity instanceof ObjectProperty) {
				$this->queuePropertyOwner($entity);
				continue;
			}
			if ($this->isTrackedObject($entity)) {
				$this->pendingReindexEntities[spl_object_id($entity)] = $entity;
			}
		}

		foreach ($uow->getScheduledEntityDeletions() as $entity) {
			if ($entity instanceof ObjectProperty) {
				$this->queuePropertyOwner($entity);
				continue;
			}
			if ($this->isTrackedObject($entity) && null !== $entity->getId()) {
				$contextType = AssetUsageIndexer::contextTypeForObject($entity);
				$contextId = $entity->getId();
				$this->pendingDeletes[$contextType.':'.$contextId] = [$contextType, $contextId];
				unset($this->pendingReindexEntities[spl_object_id($entity)]);
			}
		}
	}

	public function postFlush(PostFlushEventArgs $args): void
	{
		if ($this->processing || ([] === $this->pendingReindexEntities && [] === $this->pendingDeletes)) {
			return;
		}

		$this->processing = true;
		$entities = $this->pendingReindexEntities;
		$deletes = $this->pendingDeletes;
		$this->pendingReindexEntities = [];
		$this->pendingDeletes = [];

		try {
			foreach ($deletes as [$contextType, $contextId]) {
				$this->indexer->deleteContext($contextType, $contextId);
			}

			$reindexedKeys = [];
			foreach ($entities as $entity) {
				$id = $entity->getId();
				if (null === $id) {
					continue;
				}
				$contextType = AssetUsageIndexer::contextTypeForObject($entity);
				$key = $contextType.':'.$id;
				if (isset($deletes[$key]) || isset($reindexedKeys[$key])) {
					continue;
				}
				$reindexedKeys[$key] = true;
				$this->indexer->reindexContext($contextType, $id);
			}
		} finally {
			$this->processing = false;
		}
	}

	private function queuePropertyOwner(ObjectProperty $property): void
	{
		$name = $property->getName();
		if (null === $name || !in_array($name, AssetUsageIndexer::TRACKED_PROPERTY_NAMES, true)) {
			return;
		}
		$owner = $property->getObject();
		if (!$owner instanceof DbObject || $owner instanceof AbstractDigitalAsset) {
			return;
		}
		$this->pendingReindexEntities[spl_object_id($owner)] = $owner;
	}

	private function isTrackedObject(object $entity): bool
	{
		return $entity instanceof Page
			|| $entity instanceof Template
			|| $entity instanceof Site
			|| $entity instanceof Application;
	}
}
