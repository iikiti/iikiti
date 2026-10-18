<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\SiteGroup;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Object\UserGroup;

final class SiteTransferCatalog
{
	/** @var array<string,class-string<DbObject>> discriminator => class name */
	private array $typeMap = [];

	public function __construct(private readonly EntityManagerInterface $entityManager, private readonly DatabaseTableNameResolver $tableNames)
	{
		$base = $entityManager->getClassMetadata(DbObject::class);
		$hasAssetGrants = $this->tableNames->hasAssetGrantTable();
		foreach ($base->discriminatorMap as $discriminator => $class) {
			if (DbObject::class === $class || !is_subclass_of($class, DbObject::class) || (new \ReflectionClass($class))->isAbstract() || $this->isExcludedType($class)) {
				continue;
			}
			if (!$hasAssetGrants && is_subclass_of($class, AbstractDigitalAsset::class)) {
				continue;
			}
			$this->typeMap[$discriminator] = $class;
		}
	}

	/** @return list<array{type:class-string<DbObject>,label:string,properties:list<string>,asset:bool}> */
	public function describe(int|string $siteId): array
	{
		$types = [];
		foreach ($this->typeMap as $discriminator => $class) {
			$isAsset = is_subclass_of($class, AbstractDigitalAsset::class);
			$sql = $isAsset
				? ' SELECT DISTINCT property.name FROM object_properties property INNER JOIN objects object ON object.id = property.object_id WHERE object.type = :type AND (object.site_id = :site OR (object.type = :siteType AND object.id = :site) OR EXISTS (SELECT 1 FROM digital_asset_site_grants grant_site WHERE grant_site.asset_id = object.id AND grant_site.site_id = :site)) ORDER BY property.name'
				: ' SELECT DISTINCT property.name FROM object_properties property INNER JOIN objects object ON object.id = property.object_id WHERE object.type = :type AND (object.site_id = :site OR (object.type = :siteType AND object.id = :site)) ORDER BY property.name';
			$properties = $this->entityManager->getConnection()->fetchFirstColumn($this->sql($sql), ['type' => $discriminator, 'site' => $siteId, 'siteType' => 'site']);
			$properties = array_values(array_filter($properties, static fn (string $property): bool => !self::isSensitiveName($property)));
			$shortName = (new \ReflectionClass($class))->getShortName();
			$types[] = [
				'type' => $class,
				'label' => $shortName,
				'properties' => $properties,
				'asset' => is_subclass_of($class, AbstractDigitalAsset::class),
			];
		}

		return $types;
	}

	/**
	 * @param list<class-string<DbObject>> $classes
	 * @param array<string,list<string>>  $propertySelection
	 * @return iterable<array<string,mixed>>
	 */
	public function records(int|string $siteId, array $classes, array $propertySelection): iterable
	{
		$discriminators = [];
		$classesByDiscriminator = [];
		foreach ($this->typeMap as $discriminator => $class) {
			if (in_array($class, $classes, true)) {
				$discriminators[] = $discriminator;
				$classesByDiscriminator[$discriminator] = $class;
			}
		}
		if ([] === $discriminators) {
			throw new \InvalidArgumentException('At least one supported object type must be selected.');
		}
		$connection = $this->entityManager->getConnection();
		$siteDiscriminator = array_search(Site::class, $classesByDiscriminator, true);
		if (false !== $siteDiscriminator) {
			$siteRecord = $connection->fetchAssociative($this->sql('SELECT * FROM objects WHERE id = :id AND type = :type'), ['id' => $siteId, 'type' => $siteDiscriminator]);
			if (is_array($siteRecord)) {
				$propertiesByObject = $this->loadProperties([(int) $siteRecord['id']]);
				yield $this->serializeRecord($siteRecord, Site::class, $siteId, $propertySelection, $propertiesByObject);
			}
			unset($classesByDiscriminator[$siteDiscriminator]);
			$discriminators = array_values(array_diff($discriminators, [$siteDiscriminator]));
		}
		if ([] === $discriminators) {
			return;
		}

		$assetDiscriminators = [];
		foreach ($classesByDiscriminator as $discriminator => $class) {
			if (is_subclass_of($class, AbstractDigitalAsset::class)) {
				$assetDiscriminators[] = $discriminator;
			}
		}
		$lastId = 0;
		do {
			if ([] === $assetDiscriminators) {
				$rows = $connection->executeQuery(
					$this->sql(' SELECT object.* FROM objects object WHERE object.id > ? AND object.type IN (?) AND object.site_id = ? ORDER BY object.id ASC LIMIT 100'),
					[$lastId, $discriminators, $siteId],
					[\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
				)->fetchAllAssociative();
			} else {
				$rows = $connection->executeQuery(
					$this->sql(' SELECT object.* FROM objects object WHERE object.id > ? AND object.type IN (?) AND (object.site_id = ? OR (object.type IN (?) AND EXISTS (SELECT 1 FROM digital_asset_site_grants grant_site WHERE grant_site.asset_id = object.id AND grant_site.site_id = ?))) ORDER BY object.id ASC LIMIT 100'),
					[$lastId, $discriminators, $siteId, $assetDiscriminators, $siteId],
					[\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
				)->fetchAllAssociative();
			}
			if ([] === $rows) {
				break;
			}
			$lastId = (int) end($rows)['id'];
			$objectIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
			$propertiesByObject = $this->loadProperties($objectIds);

			foreach ($rows as $row) {
				$discriminator = (string) $row['type'];
				$class = $classesByDiscriminator[$discriminator] ?? null;
				if (null === $class) {
					continue;
				}
				yield $this->serializeRecord($row, $class, $siteId, $propertySelection, $propertiesByObject);
			}
		} while (count($rows) === 100);
	}

	/** @return array<class-string<DbObject>,string> class name => discriminator */
	public function getSupportedTypes(): array
	{
		$result = [];
		foreach ($this->typeMap as $discriminator => $class) {
			$result[$class] = $discriminator;
		}

		return $result;
	}

	/** @phpstan-assert-if-true class-string<DbObject> $class */
	public function isSupportedType(string $class): bool
	{
		return isset($this->getSupportedTypes()[$class]);
	}

	public static function isSensitiveName(string $name): bool
	{
		return (bool) preg_match('/password|token|secret|credential|private.?key|storage.?key/i', $name);
	}

	private function isExcludedType(string $class): bool
	{
		return in_array($class, [DbObject::class, User::class, ApiToken::class, UserGroup::class, SiteGroup::class, Application::class], true);
	}

	/** @param list<int> $objectIds
	 *  @return array<string,array<string,mixed>>
	 */
	private function loadProperties(array $objectIds): array
	{
		if ([] === $objectIds) {
			return [];
		}
		$rows = $this->entityManager->getConnection()->executeQuery(
			$this->sql(' SELECT object_id, name, value FROM object_properties WHERE object_id IN (?) ORDER BY object_id, name'),
			[$objectIds],
			[ArrayParameterType::INTEGER],
		)->fetchAllAssociative();
		$properties = [];
		foreach ($rows as $row) {
			$name = (string) $row['name'];
			if (!self::isSensitiveName($name)) {
				$properties[(string) $row['object_id']][$name] = self::decodeJson($row['value']);
			}
		}

		return $properties;
	}

	/** @param array<string,mixed> $row
	 *  @param array<string,list<string>> $propertySelection
	 *  @param array<string,array<string,mixed>> $propertiesByObject
	 *  @return array<string,mixed>
	 */
	/** @param class-string<DbObject> $class
	 *  @param array<string,mixed> $row
	 *  @param array<string,list<string>> $propertySelection
	 *  @param array<string,array<string,mixed>> $propertiesByObject
	 *  @return array<string,mixed>
	 */
	private function serializeRecord(array $row, string $class, int|string $siteId, array $propertySelection, array $propertiesByObject): array
	{
		$metadata = $this->entityManager->getClassMetadata($class);
		$fields = [];
		foreach ($metadata->fieldMappings as $field => $mapping) {
			if (in_array($field, ['id', 'site', 'creator_id'], true) || self::isSensitiveName($field)) {
				continue;
			}
			if (is_subclass_of($class, AbstractDigitalAsset::class) && 'externalUri' === $field) {
				continue;
			}
			$column = $mapping->columnName;
			if (array_key_exists($column, $row)) {
				$fields[$field] = self::encodeField($row[$column], $mapping->type);
			}
		}
		$properties = $propertiesByObject[(string) $row['id']] ?? [];
		if (array_key_exists($class, $propertySelection)) {
			$properties = array_intersect_key($properties, array_fill_keys($propertySelection[$class], true));
		}
		$record = ['sourceId' => (string) $row['id'], 'type' => $class, 'siteId' => (string) $siteId, 'fields' => $fields, 'properties' => $properties];
		if (is_subclass_of($class, AbstractDigitalAsset::class)) {
			$record['asset'] = [
				'id' => (string) $row['id'],
				'uploaded' => str_ends_with($class, '\\UploadedDigitalAsset'),
				'mimeType' => $row['asset_mime_type'] ?? 'application/octet-stream',
				'title' => $row['asset_title'] ?? '',
				'originalName' => $row['asset_original_name'] ?? null,
				'size' => isset($row['asset_size']) ? (int) $row['asset_size'] : null,
				'checksum' => $row['asset_checksum'] ?? null,
				'visibility' => $row['asset_visibility'] ?? 'private',
				'downloadPath' => '/api/admin/site-transfer/exports/{exportId}/assets/'.rawurlencode((string) $row['id']),
				'externalUri' => self::safeExternalUri($row['asset_external_uri'] ?? null),
			];
		}

		return $record;
	}

	private static function decodeJson(mixed $value): mixed
	{
		if (!is_string($value)) {
			return $value;
		}
		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return $value;
		}
	}

	private static function safeExternalUri(mixed $value): ?string
	{
		$parts = is_string($value) ? parse_url($value) : false;
		if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return null;
		}

		return $value;
	}

	private static function encodeField(mixed $value, string $type): mixed
	{
		if (null === $value) {
			return null;
		}
		if (str_contains($type, 'datetime') || str_contains($type, 'date')) {
			return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
		}
		if (Types::JSON === $type) {
			return self::decodeJson($value);
		}
		if (Types::BOOLEAN === $type) {
			return (bool) $value;
		}

		return $value;
	}

	private function sql(string $sql): string
	{
		return $this->tableNames->qualifySql($sql);
	}
}
