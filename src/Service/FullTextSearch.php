<?php

namespace iikiti\CMS\Service;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Entity\DbObject;

/**
 * Full-text search service.
 */
#[AsEntityListener(
	event: Events::postPersist,
	method: 'postPersist',
	entity: DbObject::class
)]
class FullTextSearch
{
	public function __construct()
	{
	}

	/**
	 * Doctrine entity listeners receive the entity first, then the event args
	 * (see ListenersInvoker::invoke). Accepting only the event args made every
	 * DbObject persist fail with a TypeError.
	 *
	 * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
	 */
	public function postPersist(DbObject $entity, LifecycleEventArgs $args): void
	{
		// Indexing is not implemented yet; the listener only needs to accept the
		// entity without failing the persist.
	}
}
