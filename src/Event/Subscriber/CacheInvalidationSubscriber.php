<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Service\DatabaseCacheManager;

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

	public function __construct(
		private readonly DatabaseCacheManager $cacheManager,
	) {
	}

	/**
	 * @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args
	 */
	public function onFlush(LifecycleEventArgs $args): void
	{
		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		$classes = array_merge(
			$this->extractClasses($uow->getScheduledEntityInsertions()),
			$this->extractClasses($uow->getScheduledEntityUpdates()),
			$this->extractClasses($uow->getScheduledEntityDeletions())
		);

		$this->affectedClasses = array_values(array_unique(array_filter($classes)));
	}

	/**
	 * @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args
	 */
	public function postFlush(LifecycleEventArgs $args): void
	{
		foreach ($this->affectedClasses as $entityClass) {
			$this->cacheManager->invalidate($entityClass);
		}
		$this->affectedClasses = [];
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
