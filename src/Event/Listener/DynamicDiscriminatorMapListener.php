<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Doctrine\UnknownDbObjectClassGenerator;
use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\UnknownDbObject;
use iikiti\CMS\Plugin\DbObjectSubtypeProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
class DynamicDiscriminatorMapListener
{
	/**
	 * @param iterable<DbObjectSubtypeProviderInterface> $subtypeProviders
	 */
	public function __construct(
		private readonly ManagerRegistry $registry,
		#[AutowireIterator('iikiti.cms.db_object_subtype')]
		private readonly iterable $subtypeProviders = [],
		private readonly ?UnknownDbObjectClassGenerator $unknownClassGenerator = null,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole = 'api',
		private readonly ?DatabaseTableNameResolver $tableNames = null,
	) {
	}

	public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
	{
		$metadata = $args->getClassMetadata();

		if (DbObject::class !== $metadata->getName()) {
			return;
		}

		if (!$metadata->isRootEntity()) {
			return;
		}

		$this->buildDiscriminatorMap($metadata);
	}

	/**
	 * @param ClassMetadata<object> $metadata
	 */
	private function buildDiscriminatorMap(ClassMetadata $metadata): void
	{
		$em = $this->registry->getManager();
		if (!$em instanceof EntityManager) {
			return;
		}

		$driver = $em->getConfiguration()->getMetadataDriverImpl();

		if (null === $driver) {
			return;
		}

		$baseFqcn = $metadata->getName();
		$map = [\strtolower($metadata->getReflectionClass()->getShortName()) => $baseFqcn];

		foreach ($driver->getAllClassNames() as $candidate) {
			if (!\is_subclass_of($candidate, $baseFqcn) || \is_subclass_of($candidate, UnknownDbObject::class)) {
				continue;
			}

			$reflection = new \ReflectionClass($candidate);
			if ($reflection->isAbstract()) {
				continue;
			}

			$discriminator = $this->resolveDiscriminatorKey($reflection);
			$this->registerMappingEntry($map, $discriminator, $candidate);
		}

		foreach ($this->subtypeProviders as $provider) {
			foreach ($provider->getDbObjectSubtypes() as $discriminator => $candidate) {
				if (!\class_exists($candidate) || !\is_subclass_of($candidate, $baseFqcn) || \is_subclass_of($candidate, UnknownDbObject::class)) {
					throw new \LogicException(sprintf('DbObject subtype "%s" for discriminator "%s" must be a concrete subclass of DbObject.', $candidate, (string) $discriminator));
				}

				$reflection = new \ReflectionClass($candidate);
				if ($reflection->isAbstract()) {
					throw new \LogicException(sprintf('DbObject subtype "%s" for discriminator "%s" cannot be abstract.', $candidate, (string) $discriminator));
				}

				$key = strtolower(trim((string) $discriminator));
				if ('' === $key) {
					throw new \LogicException(sprintf('DbObject discriminator for "%s" cannot be empty.', $candidate));
				}

				$this->registerMappingEntry($map, $key, $candidate);
			}
		}

		foreach ($this->discoverRetainedUnknownDiscriminators($em, $map) as $unknownType) {
			if (null === $this->unknownClassGenerator) {
				break;
			}

			$proxyClass = $this->unknownClassGenerator->ensureClassForDiscriminator($unknownType);
			$this->registerMappingEntry($map, $unknownType, $proxyClass);
		}

		$metadata->discriminatorMap = [];
		$metadata->subClasses = [];
		$metadata->setDiscriminatorMap($map);
	}

	/**
	 * @param \ReflectionClass<object> $reflection
	 */
	private function resolveDiscriminatorKey(\ReflectionClass $reflection): string
	{
		$attributes = $reflection->getAttributes(DbObjectDiscriminator::class);
		if ([] !== $attributes) {
			/** @var DbObjectDiscriminator $attr */
			$attr = $attributes[0]->newInstance();
			$key = strtolower(trim($attr->value));
			if ('' === $key) {
				throw new \LogicException(sprintf('DbObjectDiscriminator on "%s" cannot be empty.', $reflection->getName()));
			}

			return $key;
		}

		return \strtolower($reflection->getShortName());
	}

	/**
	 * @param array<string, string> $map
	 */
	private function registerMappingEntry(array &$map, string $discriminator, string $class): void
	{
		if (isset($map[$discriminator]) && $map[$discriminator] !== $class) {
			throw new \LogicException(sprintf(
				'Duplicate DbObject discriminator "%s": claimed by both "%s" and "%s".',
				$discriminator,
				$map[$discriminator],
				$class,
			));
		}

		$map[$discriminator] = $class;
	}

	/**
	 * Discover persisted or plugin-recorded discriminator values that are no
	 * longer backed by a loaded entity class. Strictly skipped in database-free
	 * Consumer mode and resilient to offline/pre-migration databases.
	 *
	 * @param array<string, string> $knownMap
	 *
	 * @return list<string>
	 */
	private function discoverRetainedUnknownDiscriminators(EntityManager $em, array $knownMap): array
	{
		if ('consumer' === $this->applicationRole || null === $this->unknownClassGenerator) {
			return [];
		}

		$discovered = [];
		$connection = $em->getConnection();

		$registryTable = $this->resolveTableName($em, 'plugin_registry');
		$rows = $this->fetchFirstColumnSafely(
			$connection,
			sprintf('SELECT discriminators FROM %s WHERE discriminators IS NOT NULL', $registryTable),
		);
		foreach ($rows as $raw) {
			if (!is_string($raw) || '' === $raw) {
				continue;
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				continue;
			}
			foreach ($decoded as $type) {
				if ($this->isValidRetainedDiscriminator($type) && !isset($knownMap[$type])) {
					$discovered[$type] = true;
				}
			}
		}

		$lookupTable = $this->resolveTableName($em, 'object_lookup');
		$types = $this->fetchFirstColumnSafely(
			$connection,
			sprintf('SELECT DISTINCT type FROM %s WHERE type IS NOT NULL', $lookupTable),
		);
		foreach ($types as $type) {
			if ($this->isValidRetainedDiscriminator($type) && !isset($knownMap[$type])) {
				$discovered[$type] = true;
			}
		}

		return array_slice(array_keys($discovered), 0, 256);
	}

	/**
	 * Executes a speculative metadata discovery query without aborting an active
	 * SQL transaction on PostgreSQL when the schema has not yet migrated.
	 *
	 * @return list<mixed>
	 */
	private function fetchFirstColumnSafely(Connection $connection, string $sql): array
	{
		$useSavepoint = false;
		$savepoint = '';

		try {
			$useSavepoint = $connection->isTransactionActive() && $connection->getDatabasePlatform()->supportsSavepoints();
			if ($useSavepoint) {
				$savepoint = 'iikiti_disc_'.bin2hex(random_bytes(4));
				$connection->createSavepoint($savepoint);
			}

			$rows = $connection->fetchFirstColumn($sql);
			if ($useSavepoint) {
				$connection->releaseSavepoint($savepoint);
			}

			return $rows;
		} catch (\Throwable) {
			if ($useSavepoint && '' !== $savepoint) {
				try {
					$connection->rollbackSavepoint($savepoint);
				} catch (\Throwable) {
					// Best-effort cleanup; ignore savepoint teardown failures.
				}
			}

			return [];
		}
	}

	/**
	 * @phpstan-assert-if-true string $type
	 */
	private function isValidRetainedDiscriminator(mixed $type): bool
	{
		return is_string($type) && '' !== $type && strlen($type) <= 128 && 1 !== preg_match('/[\x00-\x1f\x7f]/', $type);
	}

	private function resolveTableName(EntityManager $em, string $table): string
	{
		if (null !== $this->tableNames) {
			return $this->tableNames->resolve($table);
		}

		if (!$em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return $table;
		}

		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (is_string($schema) && '' !== $schema && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
			return $schema.'.'.$table;
		}

		return $table;
	}
}
