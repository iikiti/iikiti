<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageSkipped;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\AssetStorage;

final class SiteTransferImporter
{
	private const MAX_ASSET_BYTES = 26214400;

	private const MIME_EXTENSIONS = [
		'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
		'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/markdown' => 'md', 'text/csv' => 'csv',
		'application/json' => 'json', 'application/xml' => 'xml', 'text/xml' => 'xml',
	];

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly Connection $connection,
		private readonly SiteTransferCatalog $catalog,
		private readonly SiteTransferCredentialVault $credentials,
		private readonly RemoteSiteClient $remote,
		private readonly AssetStorage $storage,
		private readonly SiteRepository $sites,
		private readonly DatabaseTableNameResolver $tableNames,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function importRecord(array $payload, BatchMessageContext $context): void
	{
		$record = $payload['record'] ?? null;
		$type = is_array($record) ? ($record['type'] ?? null) : null;
		if (!is_array($record) || !is_string($type) || !$this->catalog->isSupportedType($type)) {
			throw new \InvalidArgumentException('Import record type is unsupported.');
		}
		if (Site::class === $type && is_array($record['properties'] ?? null)) {
			unset($record['properties']['name'], $record['properties'][Site::DOMAIN_PROPERTY_KEY]);
		}
		$targetSiteId = $payload['destinationSiteId'] ?? null;
		$sourceSiteId = $payload['sourceSiteId'] ?? null;
		$sourceInstanceId = $payload['sourceInstanceId'] ?? null;
		if (!is_string($sourceInstanceId) || !is_scalar($sourceSiteId) || !is_scalar($targetSiteId)) {
			throw new \InvalidArgumentException('Import record identity is incomplete.');
		}
		$unresolved = [];
		$record['fields'] = $this->remapKnownReferences(is_array($record['fields'] ?? null) ? $record['fields'] : [], $sourceInstanceId, (string) $sourceSiteId, (string) $targetSiteId, $unresolved);
		$record['properties'] = $this->remapKnownReferences(is_array($record['properties'] ?? null) ? $record['properties'] : [], $sourceInstanceId, (string) $sourceSiteId, (string) $targetSiteId, $unresolved);
		foreach ($unresolved as $reference) {
			$context->report('A selected object references a source object that was not imported or mapped.', $reference);
		}
		$creatorId = $this->connection->fetchOne($this->sql('SELECT created_by_user_id FROM batch_jobs WHERE id = :id'), ['id' => $context->batchId]);
		if (!is_scalar($creatorId) || '' === (string) $creatorId) {
			throw new \InvalidArgumentException('The importing administrator no longer exists.');
		}
		if (is_subclass_of($type, AbstractDigitalAsset::class)) {
			$this->importAsset($record, $payload, $context, $sourceInstanceId, (string) $sourceSiteId, (string) $targetSiteId, (string) $creatorId);
			return;
		}
		$this->importObject($record, $sourceInstanceId, (string) $sourceSiteId, (string) $targetSiteId, (string) $creatorId);
	}

	/** @param array<string,mixed> $record */
	private function importObject(array $record, string $sourceInstanceId, string $sourceSiteId, string $destinationSiteId, string $creatorId): void
	{
		$type = $record['type'] ?? null;
		if (!is_string($type) || !$this->catalog->isSupportedType($type)) {
			throw new \InvalidArgumentException('Import object type is unsupported.');
		}
		$sourceId = (string) ($record['sourceId'] ?? '');
		if (!ctype_digit($sourceId)) {
			throw new \InvalidArgumentException('Source object ID is invalid.');
		}
		$existingId = $this->findMapped($sourceInstanceId, $sourceSiteId, $type, $sourceId, $destinationSiteId);
		$metadata = $this->entityManager->getClassMetadata($type);
		$object = null === $existingId ? null : $this->entityManager->find($type, $existingId);
		$isNew = !$object instanceof DbObject;
		if ($isNew) {
			$object = $metadata->newInstance();
			if (!$object instanceof DbObject) {
				throw new \UnexpectedValueException('Mapped transfer type is not a CMS database object.');
			}
			$object->setProperties(new ArrayCollection());
		}
		if (Site::class === $type) {
			$object->setSiteId(0);
		} else {
			$object->setSiteId((int) $destinationSiteId);
			$object->setCreatorId((int) $creatorId);
		}
		if (isset($record['createdAt']) && is_string($record['createdAt'])) {
			$object->setCreatedDate(new \DateTimeImmutable($record['createdAt']));
		} elseif ($isNew) {
			$object->setCreatedDate(new \DateTimeImmutable());
		}
		foreach (($record['fields'] ?? []) as $field => $value) {
			if (!is_string($field) || in_array($field, ['id', 'site', 'creator_id'], true) || SiteTransferCatalog::isSensitiveName($field) || !$metadata->hasField($field)) {
				continue;
			}
			$mapping = $metadata->getFieldMapping($field);
			$value = $this->normalizeFieldValue($value, $mapping->type);
			$metadata->setFieldValue($object, $field, $value);
		}
		foreach (($record['properties'] ?? []) as $name => $value) {
			if (is_string($name) && !SiteTransferCatalog::isSensitiveName($name)) {
				$object->setProperty($name, $value);
			}
		}
		$this->connection->beginTransaction();
		try {
			$this->entityManager->persist($object);
			$this->entityManager->flush();
			$this->storeMap($sourceInstanceId, $sourceSiteId, $type, $sourceId, $destinationSiteId, $object->getId());
			$this->connection->commit();
		} catch (\Throwable $exception) {
			$this->connection->rollBack();
			$this->entityManager->clear();
			throw $exception;
		} finally {
			$this->entityManager->clear();
		}
	}

	/** @param array<string,mixed> $record
	 *  @param array<string,mixed> $payload
	 */
	private function importAsset(array $record, array $payload, BatchMessageContext $context, string $sourceInstanceId, string $sourceSiteId, string $destinationSiteId, string $creatorId): void
	{
		$assetInfo = $record['asset'] ?? null;
		if (!is_array($assetInfo)) {
			throw new \InvalidArgumentException('Digital asset metadata is missing.');
		}
		$type = $record['type'] ?? null;
		if (!is_string($type) || !$this->catalog->isSupportedType($type) || !is_subclass_of($type, AbstractDigitalAsset::class)) {
			throw new \InvalidArgumentException('Digital asset subtype is unsupported.');
		}
		$sourceId = (string) ($assetInfo['id'] ?? $record['sourceId'] ?? '');
		$targetSite = $this->sites->find($destinationSiteId);
		if (!$targetSite instanceof Site) {
			throw new \InvalidArgumentException('Destination site no longer exists.');
		}
		$existingId = $this->findMapped($sourceInstanceId, $sourceSiteId, $type, $sourceId, $destinationSiteId);
		$existing = null === $existingId ? null : $this->entityManager->find($type, $existingId);
		$mimeType = strtolower((string) ($assetInfo['mimeType'] ?? 'application/octet-stream'));
		$title = trim((string) ($assetInfo['title'] ?? 'Imported asset')) ?: 'Imported asset';
		$externalUri = $assetInfo['externalUri'] ?? null;
		$isUploaded = is_subclass_of($type, UploadedDigitalAsset::class);
		if ($isUploaded !== (bool) ($assetInfo['uploaded'] ?? false)) {
			throw new \InvalidArgumentException('Digital asset subtype does not match its source metadata.');
		}
		if (!$isUploaded) {
			$uri = is_string($externalUri) ? parse_url($externalUri) : false;
			if (!is_array($uri) || 'https' !== strtolower((string) ($uri['scheme'] ?? '')) || empty($uri['host']) || isset($uri['user']) || isset($uri['pass']) || isset($uri['query']) || isset($uri['fragment'])) {
				throw new BatchMessageSkipped('External asset URL is unavailable or contains credentials.');
			}
		}
		$storageKey = null;
		$size = null;
		$checksum = null;
		if ($isUploaded) {
			if (!isset(self::MIME_EXTENSIONS[$mimeType])) {
				throw new \InvalidArgumentException('Source asset MIME type is not supported for upload.');
			}
			$token = $this->credentials->get($context->batchId);
			$response = $this->remote->downloadAsset((string) $payload['sourceUrl'], (string) $payload['exportId'], $sourceId, $token);
			$headers = $response->getHeaders(false);
			$declaredSize = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
			if (null !== $declaredSize && ($declaredSize < 1 || $declaredSize > self::MAX_ASSET_BYTES)) {
				throw new \InvalidArgumentException('Source asset is outside the supported 1–25 MB size range.');
			}
			$tempPath = $this->storage->createTemporaryPath(self::MIME_EXTENSIONS[$mimeType]);
			$handle = fopen($tempPath, 'wb');
			if (false === $handle) {
				@unlink($tempPath);
				throw new \RuntimeException('Unable to prepare protected temporary asset storage.');
			}
			try {
				$bytes = 0;
				foreach ($this->remote->stream($response) as $chunk) {
					if ($chunk->isTimeout()) {
						continue;
					}
					$contents = $chunk->getContent();
					$bytes += strlen($contents);
					if ($bytes > self::MAX_ASSET_BYTES) {
						throw new \InvalidArgumentException('Source asset exceeds the 25 MB transfer limit.');
					}
					if (strlen($contents) !== fwrite($handle, $contents)) {
						throw new \RuntimeException('Unable to write the streamed asset to protected temporary storage.');
					}
				}
				fflush($handle);
			} catch (\Throwable $exception) {
				@unlink($tempPath);
				throw $exception;
			} finally {
				fclose($handle);
			}
			try {
				$actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath);
				if ($bytes < 1 || $actualMime !== $mimeType || (isset($assetInfo['size']) && (int) $assetInfo['size'] !== $bytes)) {
					throw new \InvalidArgumentException('Source asset content does not match its declared size or MIME type.');
				}
				$checksum = hash_file('sha256', $tempPath);
				if (is_string($assetInfo['checksum'] ?? null) && !hash_equals($assetInfo['checksum'], (string) $checksum)) {
					throw new \InvalidArgumentException('Source asset checksum verification failed.');
				}
				$storageKey = $this->storage->storeTemporaryFile($tempPath, $mimeType);
				$size = $bytes;
			} catch (\Throwable $exception) {
				@unlink($tempPath);
				throw $exception;
			}
		}

		$oldStorageKey = $existing instanceof AbstractDigitalAsset ? $existing->getStorageKey() : null;
		if ($existing instanceof AbstractDigitalAsset && $existing::class === $type) {
			$asset = $existing;
			$asset->setTitle($title)->setMimeType($mimeType);
		} elseif (is_subclass_of($type, UploadedDigitalAsset::class)) {
			$asset = new UploadedDigitalAsset($title, $mimeType, bin2hex(random_bytes(32)));
		} elseif (is_subclass_of($type, ExternalDigitalAsset::class)) {
			$asset = new ExternalDigitalAsset($title, $mimeType, bin2hex(random_bytes(32)));
		} else {
			throw new \InvalidArgumentException('Unsupported digital asset subtype.');
		}
		$asset->setSiteId((int) $destinationSiteId);
		$asset->setCreatorId((int) $creatorId);
		$asset->setCreatedDate(new \DateTimeImmutable());
		$asset->setAccessSites([$targetSite]);
		$asset->setVisibility(in_array(($assetInfo['visibility'] ?? 'private'), ['private', 'public'], true) ? $assetInfo['visibility'] : 'private');
		$asset->setOriginalName(isset($assetInfo['originalName']) ? (string) $assetInfo['originalName'] : null);
		$asset->setExternalUri(is_string($externalUri) ? $externalUri : null);
		if (null !== $storageKey) {
			$asset->setStorageKey($storageKey)->setSize($size)->setChecksum($checksum);
		}
		$asset->setProcessingStatus('complete');
		foreach (($record['properties'] ?? []) as $name => $value) {
			if (is_string($name) && !SiteTransferCatalog::isSensitiveName($name)) {
				$asset->setProperty($name, $value);
			}
		}
		try {
			$this->connection->beginTransaction();
			$this->entityManager->persist($asset);
			$this->entityManager->flush();
			$this->storeMap($sourceInstanceId, $sourceSiteId, $type, $sourceId, $destinationSiteId, $asset->getId());
			$this->connection->commit();
			$this->entityManager->clear();
			if (null !== $oldStorageKey && $oldStorageKey !== $storageKey) {
				$this->storage->delete($oldStorageKey);
			}
		} catch (\Throwable $exception) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}
			$this->entityManager->clear();
			if (null !== $storageKey) {
				$this->storage->delete($storageKey);
			}
			throw $exception;
		}
	}

	private function findMapped(string $instanceId, string $sourceSiteId, string $type, string $sourceId, string $destinationSiteId): int|string|null
	{
		$id = $this->connection->fetchOne(
			$this->sql(' SELECT destination_object_id FROM site_transfer_object_map WHERE source_instance_id = :instance AND source_site_id = :sourceSite AND source_object_type = :type AND source_object_id = :sourceId AND destination_site_id = :destinationSite'),
			['instance' => $instanceId, 'sourceSite' => $sourceSiteId, 'type' => $type, 'sourceId' => $sourceId, 'destinationSite' => $destinationSiteId],
		);

		return false === $id ? null : $id;
	}

	private function storeMap(string $instanceId, string $sourceSiteId, string $type, string $sourceId, string $destinationSiteId, int|string|null $destinationObjectId): void
	{
		if (null === $destinationObjectId) {
			throw new \LogicException('Imported object must be persisted before mapping its source ID.');
		}
		$this->connection->executeStatement(
			$this->sql(' INSERT INTO site_transfer_object_map (source_instance_id, source_site_id, source_object_type, source_object_id, destination_site_id, destination_object_id, created_at, updated_at) VALUES (:instance, :sourceSite, :type, :sourceId, :destinationSite, :destinationId, :now, :now) ON CONFLICT (source_instance_id, source_site_id, source_object_type, source_object_id, destination_site_id) DO UPDATE SET destination_object_id = EXCLUDED.destination_object_id, updated_at = EXCLUDED.updated_at'),
			['instance' => $instanceId, 'sourceSite' => $sourceSiteId, 'type' => $type, 'sourceId' => $sourceId, 'destinationSite' => $destinationSiteId, 'destinationId' => $destinationObjectId, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
		);
	}

	private function normalizeFieldValue(mixed $value, string $type): mixed
	{
		if (null === $value) {
			return null;
		}
		if (str_contains($type, 'datetime') || str_contains($type, 'date')) {
			return $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
		}
		if (in_array($type, [Types::INTEGER, Types::SMALLINT], true)) {
			return (int) $value;
		}
		if (Types::BOOLEAN === $type) {
			return (bool) $value;
		}
		if (Types::STRING === $type || Types::TEXT === $type) {
			return (string) $value;
		}

		return $value;
	}

	/**
	 * @param array<mixed> $values
	 * @param list<array{property:string,sourceId:string}> $unresolved
	 * @return array<mixed>
	 */
	private function remapKnownReferences(array $values, string $instanceId, string $sourceSiteId, string $destinationSiteId, array &$unresolved): array
	{
		$knownReferenceKeys = ['assetid', 'objectid', 'pageid', 'templateid', 'shellid', 'parentid'];
		foreach ($values as $key => $value) {
			if (is_array($value)) {
				$values[$key] = $this->remapKnownReferences($value, $instanceId, $sourceSiteId, $destinationSiteId, $unresolved);
				continue;
			}
			if (!is_string($key)) {
				continue;
			}
			$normalizedKey = strtolower(str_replace('_', '', $key));
			if ('siteid' === $normalizedKey && is_scalar($value) && (string) $value === $sourceSiteId) {
				$values[$key] = $destinationSiteId;
				continue;
			}
			if (!in_array($normalizedKey, $knownReferenceKeys, true) || (!is_int($value) && !(is_string($value) && ctype_digit($value)))) {
				continue;
			}
			$mappedId = $this->connection->fetchOne(
				$this->sql(' SELECT destination_object_id FROM site_transfer_object_map WHERE source_instance_id = :instance AND source_site_id = :sourceSite AND source_object_id = :sourceId AND destination_site_id = :destinationSite LIMIT 1'),
				['instance' => $instanceId, 'sourceSite' => $sourceSiteId, 'sourceId' => (string) $value, 'destinationSite' => $destinationSiteId],
			);
			if (false === $mappedId) {
				$unresolved[] = ['property' => $key, 'sourceId' => (string) $value];
				continue;
			}
			$values[$key] = $mappedId;
		}

		return $values;
	}

	private function sql(string $sql): string
	{
		return $this->tableNames->qualifySql($sql);
	}
}
