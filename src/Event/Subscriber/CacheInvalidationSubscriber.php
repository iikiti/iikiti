<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Service\DatabaseCacheManager;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;

/**
 * Invalidates database query cache entries when entities are written.
 *
 * Entity class names scheduled for insertion, update or deletion are collected
 * in onFlush and applied in postFlush, after the database write has completed.
 * This bumps the per-entity-class generation so previously cached query
 * results become unreachable.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class CacheInvalidationSubscriber
{
	/** @var array<int,string> */
	private array $affectedClasses = [];
	private bool $renderedFragmentsAffected = false;

	public function __construct(
		private readonly DatabaseCacheManager $cacheManager,
		private readonly ?RenderedFragmentCache $renderedFragmentCache = null,
	) {
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		$entities = array_merge(
			$uow->getScheduledEntityInsertions(),
			$uow->getScheduledEntityUpdates(),
			$uow->getScheduledEntityDeletions(),
		);
		$classes = $this->extractClasses($entities);
		$this->renderedFragmentsAffected = false;
		foreach ($entities as $entity) {
			if ($entity instanceof DbObject) {
				$classes[] = DbObject::class;
				$this->renderedFragmentsAffected = true;
			} elseif ($entity instanceof ObjectProperty) {
				$classes[] = ObjectProperty::class;
				$this->renderedFragmentsAffected = true;
			}
		}

		$this->affectedClasses = array_values(array_unique(array_filter($classes)));
	}

	public function postFlush(PostFlushEventArgs $args): void
	{
		foreach ($this->affectedClasses as $entityClass) {
			$this->cacheManager->invalidate($entityClass);
		}
		if ($this->renderedFragmentsAffected) {
			$this->renderedFragmentCache?->invalidateCmsDependencies();
		}
		$this->affectedClasses = [];
		$this->renderedFragmentsAffected = false;
	}

	/**
	 * @param array<object> $entities
	 *
	 * @return array<int,string>
	 */
	private function extractClasses(array $entities): array
	{
		return array_map(static fn (object $entity): string => get_class($entity), $entities);
	}
}
