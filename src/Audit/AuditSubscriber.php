<?php

namespace iikiti\CMS\Audit;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\ObjectProperty;

/**
 * Automatically logs entity lifecycle changes to the audit log.
 *
 * Hooks into Doctrine's {@see Events::onFlush} event to capture inserts,
 * updates, and deletes with their before/after state. The {@see AuditLogger}
 * service records each change.
 *
 * Audit log entries themselves are excluded from logging to prevent recursion.
 * Because these log entries are persisted during onFlush (before SQL is
 * executed), they must not call flush() again — doing so would re-trigger
 * onFlush and create an infinite loop. Passing $flush=false lets the outer
 * flush() persist both the original entities and the audit entries.
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

		// Entities persisted above (audit log entries) were not part of the
		// changesets computed before onFlush was dispatched. Recompute so the
		// outer flush() can insert them with proper column data.
		$unitOfWork->computeChangeSets();
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

			$type = $this->getShortType($entity);
			$this->auditLogger->log(
				'created',
				$type,
				$entity instanceof DbObject ? $entity->getId() : null,
				null,
				$this->extractState($entity),
				'user',
				['source' => 'doctrine_listener'],
				false,
				sprintf('Created %s%s', $type, $this->idSuffix($entity)),
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

			$type = $this->getShortType($entity);
			$this->auditLogger->log(
				'updated',
				$type,
				$entity instanceof DbObject ? $entity->getId() : null,
				$beforeState,
				$afterState,
				'user',
				['source' => 'doctrine_listener', 'changes' => $this->flattenChangeSet($changeSet)],
				false,
				sprintf('Updated %s%s', $type, $this->idSuffix($entity)),
			);
		}
	}

	private function logDeletions(UnitOfWork $unitOfWork): void
	{
		foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
			if ($entity instanceof AuditLogEntry) {
				continue;
			}

			$type = $this->getShortType($entity);
			$this->auditLogger->log(
				'deleted',
				$type,
				$entity instanceof DbObject ? $entity->getId() : null,
				$this->extractState($entity),
				null,
				'user',
				['source' => 'doctrine_listener'],
				false,
				$this->describeDeletion($entity, $type),
			);
		}
	}

	/**
	 * Builds a readable summary for a deletion. Properties get a specific
	 * sentence naming the property; other entities use the type and id.
	 */
	private function describeDeletion(object $entity, string $type): string
	{
		if ($entity instanceof ObjectProperty) {
			$name = $entity->getName() ?? 'unnamed';
			$owner = $entity->getObject();
			$ownerId = $owner instanceof DbObject ? $owner->getId() : '?';

			return sprintf('Deleted property "%s" from object %s', $name, $ownerId);
		}

		$id = $entity instanceof DbObject ? $entity->getId() : null;

		return null === $id
			? sprintf('Deleted %s', $type)
			: sprintf('Deleted %s %s', $type, $id);
	}

	private function idSuffix(object $entity): string
	{
		$id = $entity instanceof DbObject ? $entity->getId() : null;

		return null === $id ? '' : ' '.$id;
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
		if ($entity instanceof ObjectProperty) {
			return [
				'name' => $entity->getName(),
				'value' => $entity->getValue(),
			];
		}

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
