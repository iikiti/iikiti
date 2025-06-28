<?php

namespace iikiti\CMS\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Doctrine event listener to dynamically set the database schema for entities.
 *
 * This listener intercepts the metadata loading process and applies a schema
 * configuration to entities that are part of the application's domain,
 * allowing the schema to be defined in the environment configuration rather
 * than being hard-coded in entity annotations.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class SchemaListener
{
	/**
	 * @param string $schema The database schema to apply to the entities.
	 */
	public function __construct(private readonly string $schema)
	{
	}

	/**
	 * Handles the loadClassMetadata event to dynamically set the entity schema.
	 *
	 * @param LoadClassMetadataEventArgs $args The event arguments.
	 */
	public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
	{
		$metadata = $args->getClassMetadata();

		if (false === $this->isAppEntity($metadata)) {
			return;
		}

		if ($this->schema && !$metadata->isMappedSuperclass && !isset($metadata->table['schema'])) {
			$metadata->table['schema'] = $this->schema;
		}
	}

	/**
	 * Determines if the entity is part of the application's domain.
	 *
	 * @param ClassMetadata<object> $metadata The entity metadata.
	 *
	 * @return bool True if the entity is an application entity, false otherwise.
	 */
	private function isAppEntity(ClassMetadata $metadata): bool
	{
		return str_starts_with($metadata->getName(), 'iikiti\\CMS\\Entity\\');
	}
}