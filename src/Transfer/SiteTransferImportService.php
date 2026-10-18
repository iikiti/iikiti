<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\BatchManager;
use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\Batch\BatchJob;
use iikiti\CMS\Entity\Object\Site;

final class SiteTransferImportService
{
	public function __construct(
		private readonly RemoteSiteClient $remote,
		private readonly SiteTransferCatalog $catalog,
		private readonly SiteTransferCredentialVault $credentials,
		private readonly BatchManager $batches,
		private readonly EntityManagerInterface $entityManager,
		private readonly Connection $connection,
		private readonly DatabaseTableNameResolver $tableNames,
	) {
	}

	/** @return array<string,mixed> */
	public function preview(string $sourceUrl, int|string $exportId, string $sourceToken): array
	{
		$info = $this->remote->getExportInfo($sourceUrl, $exportId, $sourceToken);
		if (!in_array($info['status'] ?? null, [BatchJob::STATUS_COMPLETED, BatchJob::STATUS_COMPLETED_WITH_ERRORS], true) || !is_array($info['manifest'] ?? null)) {
			throw new \InvalidArgumentException('Source export is not complete or its manifest is unavailable.');
		}
		$header = $info['manifest'];
		if (1 !== ($header['version'] ?? null) || !is_string($header['sourceInstanceId'] ?? null) || !is_scalar($header['sourceSiteId'] ?? null) || !is_array($header['types'] ?? null)) {
			throw new \InvalidArgumentException('Source export manifest version or identity is unsupported.');
		}
		$header['exportId'] = (string) $exportId;
		$header['jobStatus'] = $info['status'];
		$header['supportedTypes'] = array_values(array_filter($header['types'], fn (mixed $type): bool => is_string($type) && $this->catalog->isSupportedType($type)));
		$header['unsupportedTypes'] = array_values(array_diff($header['types'], $header['supportedTypes']));

		return $header;
	}

	/** @param array<string,mixed> $header
	 *  @param list<mixed> $selectedTypes
	 *  @param array<string,mixed> $propertySelection
	 */
	public function create(
		string $sourceUrl,
		int|string $exportId,
		string $sourceToken,
		array $header,
		array $selectedTypes,
		array $propertySelection,
		Site $destinationSite,
		int|string $creatorId,
		int $maxAttempts = 3,
	): BatchJob {
		$sourceUrl = $this->remote->normalizeBaseUrl($sourceUrl);
		$availableTypes = $header['types'] ?? null;
		if (!is_array($availableTypes)) {
			throw new \InvalidArgumentException('Source manifest does not declare transferable types.');
		}
		if ([] === $selectedTypes) {
			$selectedTypes = array_filter($availableTypes, fn (mixed $type): bool => is_string($type) && $this->catalog->isSupportedType($type));
		}
		$validTypes = [];
		foreach ($selectedTypes as $type) {
			if (!is_string($type) || !in_array($type, $availableTypes, true) || !$this->catalog->isSupportedType($type)) {
				throw new \InvalidArgumentException(sprintf('Object type "%s" is not available in this source export.', (string) $type));
			}
			$validTypes[] = $type;
		}
		$selectedTypes = $validTypes;
		$propertySelection = $this->validatePropertySelection($header, $selectedTypes, $propertySelection);
		$destinationSiteId = $destinationSite->getId() ?? throw new \InvalidArgumentException('Destination site must be persisted.');
		$this->storeMap((string) $header['sourceInstanceId'], (string) $header['sourceSiteId'], Site::class, (string) $header['sourceSiteId'], (string) $destinationSiteId);
		$remote = $this->remote;
		$destinationSiteId = (string) $destinationSiteId;
		$configuration = [
			'siteTransferImport' => [
				'sourceUrl' => $sourceUrl,
				'exportId' => (string) $exportId,
				'sourceInstanceId' => (string) $header['sourceInstanceId'],
				'sourceSiteId' => (string) $header['sourceSiteId'],
				'destinationSiteId' => (string) $destinationSiteId,
				'types' => $selectedTypes,
				'properties' => $propertySelection,
			],
		];
		$messages = (static function () use ($sourceUrl, $exportId, $sourceToken, $header, $selectedTypes, $propertySelection, $remote, $destinationSiteId): \Generator {
			$headerSeen = false;
			foreach ($remote->readManifest($sourceUrl, $exportId, $sourceToken) as $line) {
				if ('manifest' === ($line['kind'] ?? null)) {
					if (($line['sourceInstanceId'] ?? null) !== ($header['sourceInstanceId'] ?? null) || (string) ($line['sourceSiteId'] ?? '') !== (string) ($header['sourceSiteId'] ?? '')) {
						throw new \UnexpectedValueException('Source export identity changed while reading the manifest.');
					}
					$headerSeen = true;
					continue;
				}
				if (!$headerSeen || 'record' !== ($line['kind'] ?? null) || !is_string($line['type'] ?? null) || !in_array($line['type'], $selectedTypes, true)) {
					continue;
				}
				if (array_key_exists($line['type'], $propertySelection)) {
					$selected = array_fill_keys($propertySelection[$line['type']], true);
					$line['properties'] = array_intersect_key(is_array($line['properties'] ?? null) ? $line['properties'] : [], $selected);
				}
				$sourceId = (string) ($line['sourceId'] ?? '');
				$itemKey = 'import_'.substr(hash('sha256', $line['type'].'|'.$sourceId), 0, 48);
				$asset = is_array($line['asset'] ?? null);
				if ($asset && isset($line['asset']['downloadPath'])) {
					$line['asset']['downloadPath'] = str_replace('{exportId}', (string) $exportId, $line['asset']['downloadPath']);
				}
				$payload = [
					'record' => $line,
					'sourceUrl' => $sourceUrl,
					'exportId' => (string) $exportId,
					'sourceInstanceId' => (string) $header['sourceInstanceId'],
					'sourceSiteId' => (string) $header['sourceSiteId'],
					'destinationSiteId' => $destinationSiteId,
				];
				yield new BatchMessage($itemKey, 'site_transfer.import_record', $payload, sprintf('Import %s #%s', (new \ReflectionClass($line['type']))->getShortName(), $sourceId), 'import:'.substr(hash('sha256', $line['type'].'|'.$sourceId), 0, 64));
			}
			if (!$headerSeen) {
				throw new \UnexpectedValueException('Source manifest is empty or does not begin with a manifest header.');
			}
		})();

		$job = $this->batches->submit('Import site '.$header['sourceSiteId'], $messages, 'site.transfer.import', $destinationSiteId, $creatorId, $maxAttempts, $configuration, true);
		$jobId = $job->getId() ?? throw new \LogicException('Import batch did not receive an ID.');
		if (0 === $job->getTotalItems()) {
			return $job;
		}
		try {
			$this->credentials->store($jobId, $sourceToken);
			if (!$this->batches->start($jobId)) {
				throw new \LogicException('Import batch could not be started.');
			}
		} catch (\Throwable $exception) {
			$this->batches->cancel($jobId);
			throw $exception;
		}

		return $job;
	}

	public function refreshCredentials(int|string $jobId, string $token): bool
	{
		$job = $this->entityManager->find(BatchJob::class, $jobId);
		if (!$job instanceof BatchJob || 'site.transfer.import' !== $job->getTaskKey()) {
			return false;
		}
		$configuration = $job->getConfiguration()['siteTransferImport'] ?? null;
		if (!is_array($configuration) || !isset($configuration['sourceUrl'], $configuration['exportId'])) {
			return false;
		}
		$this->remote->getExportInfo((string) $configuration['sourceUrl'], (string) $configuration['exportId'], $token);
		$this->credentials->store($jobId, $token);
		$this->batches->resume($jobId);

		return true;
	}

	/** @param array<string,mixed> $header
	 *  @param list<string> $types
	 *  @param array<string,mixed> $selection
	 *  @return array<string,list<string>>
	 */
	private function validatePropertySelection(array $header, array $types, array $selection): array
	{
		$available = is_array($header['availableProperties'] ?? null) ? $header['availableProperties'] : [];
		$validated = [];
		foreach ($selection as $type => $properties) {
			if (!in_array($type, $types, true) || !is_array($properties)) {
				throw new \InvalidArgumentException('Property selection must belong to an imported object type.');
			}
			$validProperties = [];
			foreach ($properties as $property) {
				if (!is_string($property) || !in_array($property, $available[$type] ?? [], true) || SiteTransferCatalog::isSensitiveName($property)) {
					throw new \InvalidArgumentException(sprintf('Source property "%s" is unavailable or sensitive.', (string) $property));
				}
				$validProperties[] = $property;
			}
			$validated[$type] = $validProperties;
		}

		return $validated;
	}

	private function storeMap(string $instanceId, string $sourceSiteId, string $type, string $sourceId, string $destinationSiteId): void
	{
		$this->connection->executeStatement(
			$this->tableNames->qualifySql(' INSERT INTO site_transfer_object_map (source_instance_id, source_site_id, source_object_type, source_object_id, destination_site_id, destination_object_id, created_at, updated_at) VALUES (:instance, :sourceSite, :type, :sourceId, :destinationSite, :destinationId, :now, :now) ON CONFLICT (source_instance_id, source_site_id, source_object_type, source_object_id, destination_site_id) DO UPDATE SET destination_object_id = EXCLUDED.destination_object_id, updated_at = EXCLUDED.updated_at'),
			['instance' => $instanceId, 'sourceSite' => $sourceSiteId, 'type' => $type, 'sourceId' => $sourceId, 'destinationSite' => $destinationSiteId, 'destinationId' => $destinationSiteId, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
		);
	}
}
