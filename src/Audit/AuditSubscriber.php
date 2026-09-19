<?php

namespace iikiti\CMS\Audit;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Entity\DbObject;

/**
 * Automatically logs entity lifecycle changes to the audit log.
 *
 * Hooks into Doctrine's {@see Events::OnFlush} event to capture inserts,
 * updates, and deletes with their before/after state. The {@see AuditLogger}
 * service records each change.
 *
 * Audit log entries themselves are excluded from logging to prevent recursion.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 500)]
class AuditSubscriber
{
	public function __construct(
		private readonly AuditLogger $auditLogger,
	) {
	}

	public function onFlush(OnFlushEventArgs $args): void
	{
		$entityManager = $args->getObjectManager();
		$unitOfWork = $entityManager->getUnitOfWork();

		$this->logInsertions($unitOfWork);
		$this->logUpdates($unitOfWork);
		$this->logDeletions($unitOfWork);
	}

	/**
	 * @param UnitOfWork $unitOfWork
	 */
	private function logInsertions(UnitOfWork $unitOfWork): void
	{
		foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
			if ($entity instanceof AuditLogEntry) {
				continue;
			}

			$this->auditLogger->log(
				'created',
				$this->getShortType($entity),
				$entity instanceof DbObject ? $entity->getId() : null,
				null,
				$this->extractState($entity),
				'user',
				['source' => 'doctrine_listener'],
			);
		}
	}

	private function logUpdates(UnitOfWork $unitOfWork): void
	{
		foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
			if ($entity instanceof AuditLogEntry) {
				continue;
			}

			$changeSet = $unitOfWork->getEntityChangeSet($entity);
			$beforeState = $this->extractOldState($entity, $changeSet);
			$afterState = $this->extractNewState($entity, $changeSet);

			$this->auditLogger->log(
				'updated',
				$this->getShortType($entity),
				$entity instanceof DbObject ? $entity->getId() : null,
				$beforeState,
				$afterState,
				'user',
				['source' => 'doctrine_listener', 'changes' => $this->flattenChangeSet($changeSet)],
			);
		}
	}

	private function logDeletions(UnitOfWork $unitOfWork): void
	{
		foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
			if ($entity instanceof AuditLogEntry) {
				continue;
			}

			$this->auditLogger->log(
				'deleted',
				$this->getShortType($entity),
				$entity instanceof DbObject ? $entity->getId() : null,
				$this->extractState($entity),
				null,
				'user',
				['source' => 'doctrine_listener'],
			);
		}
	}

	private function getShortType(object $entity): string
	{
		$parts = explode('\\', get_class($entity));

		return end($parts);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function extractState(object $entity): ?array
	{
		if (!$entity instanceof DbObject) {
			return null;
		}

		$state = [
			'id' => $entity->getId(),
			'type' => $entity->getType(),
		];

		foreach ($entity->getProperties() as $property) {
			$state[$property->getName()] = $property->getValue();
		}

		return $state;
	}

	/**
	 * @param array<string,array<int,mixed>> $changeSet
	 *
	 * @return array<string,mixed>|null
	 */
	private function extractOldState(object $entity, array $changeSet): ?array
	{
		if (!$entity instanceof DbObject || empty($changeSet)) {
			return null;
		}

		$old = [];
		foreach ($changeSet as $field => $changes) {
			$old[$field] = $changes[0] ?? null;
		}

		return $old;
	}

	/**
	 * @param array<string,array<int,mixed>> $changeSet
	 *
	 * @return array<string,mixed>|null
	 */
	private function extractNewState(object $entity, array $changeSet): ?array
	{
		if (!$entity instanceof DbObject || empty($changeSet)) {
			return null;
		}

		$new = [];
		foreach ($changeSet as $field => $changes) {
			$new[$field] = $changes[1] ?? null;
		}

		return $new;
	}

	/**
	 * @param array<string,array<int,mixed>> $changeSet
	 *
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	private function flattenChangeSet(array $changeSet): array
	{
		return array_map(static fn (array $changes): array => [$changes[0] ?? null, $changes[1] ?? null], $changeSet);
	}
}
