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

final class SiteTransferExportService
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly SiteTransferCatalog $catalog,
		private readonly SiteTransferManifestStore $manifestStore,
		private readonly BatchManager $batches,
		private readonly Connection $connection,
		private readonly DatabaseTableNameResolver $tableNames,
	) {
	}

	/** @param list<string> $selectedTypes
	 *  @param array<string,list<string>> $propertySelection
	 */
	public function create(int|string $siteId, array $selectedTypes, array $propertySelection, int|string $userId): BatchJob
	{
		$plan = $this->buildPlan($siteId, $selectedTypes, $propertySelection);
		$header = $plan['header'];
		$job = $this->batches->submit('Export site '.$siteId, $this->createMessages($plan), 'site.transfer.export', $siteId, $userId, 3, ['siteTransfer' => $header]);
		$jobId = $job->getId() ?? throw new \LogicException('Export batch did not receive an ID.');
		if (0 === $job->getTotalItems()) {
			$this->manifestStore->ensureHeader($jobId, $header);
		}

		return $job;
	}

	/** @param list<mixed> $selectedTypes
	 *  @param array<string,mixed> $propertySelection
	 *  @return array{siteId:int|string,types:list<string>,properties:array<string,list<string>>,header:array<string,mixed>}
	 */
	public function buildPlan(int|string $siteId, array $selectedTypes, array $propertySelection): array
	{
		if ([] === $selectedTypes) {
			$selectedTypes = array_column($this->catalog->describe($siteId), 'type');
		}
		if (count($selectedTypes) !== count(array_unique($selectedTypes))) {
			throw new \InvalidArgumentException('Select unique object types for export.');
		}
		$supported = $this->catalog->getSupportedTypes();
		$validTypes = [];
		foreach ($selectedTypes as $type) {
			if (!is_string($type) || !isset($supported[$type])) {
				throw new \InvalidArgumentException(sprintf('Object type "%s" is not supported for transfer.', (string) $type));
			}
			$validTypes[] = $type;
		}
		$selectedTypes = $validTypes;
		$propertySelection = $this->validateProperties($siteId, $selectedTypes, $propertySelection);
		$header = $this->createHeader($siteId, $selectedTypes, $propertySelection);

		return ['siteId' => $siteId, 'types' => $selectedTypes, 'properties' => $propertySelection, 'header' => $header];
	}

	/** @param array{siteId:int|string,types:list<string>,properties:array<string,list<string>>,header:array<string,mixed>} $plan
	 *  @return iterable<BatchMessage>
	 */
	public function createMessages(array $plan): iterable
	{
		$siteId = $plan['siteId'];
		$selectedTypes = $plan['types'];
		$propertySelection = $plan['properties'];
		$header = $plan['header'];
		$catalog = $this->catalog;
		return (static function () use ($siteId, $selectedTypes, $propertySelection, $header, $catalog): \Generator {
			foreach ($catalog->records($siteId, $selectedTypes, $propertySelection) as $record) {
				$itemKey = 'record_'.substr(hash('sha256', $record['type'].'|'.$record['sourceId']), 0, 48);
				$summary = sprintf('Export %s #%s', (new \ReflectionClass($record['type']))->getShortName(), $record['sourceId']);
				yield new BatchMessage(
					$itemKey,
					'site_transfer.export_record',
					['header' => $header, 'record' => $record],
					$summary,
					'export:'.substr(hash('sha256', $record['type'].'|'.$record['sourceId']), 0, 64),
				);
			}
		})();
	}

	/** @return array<string,mixed> */
	public function getHeader(BatchJob $job): array
	{
		$header = $job->getConfiguration()['siteTransfer'] ?? null;
		if (!is_array($header) || 'site.transfer.export' !== $job->getTaskKey()) {
			throw new \InvalidArgumentException('Batch is not a site export.');
		}

		return $header;
	}

	public function getManifestPath(BatchJob $job): string
	{
		return $this->manifestStore->pathFor($job->getId() ?? throw new \LogicException('Export batch must be persisted.'));
	}

	/** @param list<string> $types
	 *  @param array<string,list<string>> $propertySelection
	 *  @return array<string,mixed>
	 */
	private function createHeader(int|string $siteId, array $types, array $propertySelection): array
	{
		$instanceId = $this->connection->fetchOne($this->tableNames->qualifySql('SELECT instance_id FROM site_transfer_identity WHERE id = 1'));
		$site = $this->entityManager->find(Site::class, $siteId);
		if (!$site instanceof Site) {
			throw new \InvalidArgumentException('Selected site does not exist.');
		}
		$properties = $site->getProperties();
		$availableProperties = [];
		foreach ($this->catalog->describe($siteId) as $type) {
			if (in_array($type['type'], $types, true)) {
				$availableProperties[$type['type']] = $type['properties'];
			}
		}

		return [
			'version' => 1,
			'sourceInstanceId' => (string) $instanceId,
			'sourceSiteId' => (string) $siteId,
			'site' => [
				'name' => $properties->get('name')?->getValue() ?? $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Imported site',
				'domain' => $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? '',
			],
			'types' => $types,
			'properties' => $propertySelection,
			'availableProperties' => $availableProperties,
		];
	}

	/** @param list<string> $types
	 *  @param array<string,mixed> $selection
	 *  @return array<string,list<string>>
	 */
	private function validateProperties(int|string $siteId, array $types, array $selection): array
	{
		$catalog = [];
		foreach ($this->catalog->describe($siteId) as $type) {
			$catalog[$type['type']] = array_fill_keys($type['properties'], true);
		}
		$validatedSelection = [];
		foreach ($selection as $type => $properties) {
			if (!in_array($type, $types, true) || !is_array($properties)) {
				throw new \InvalidArgumentException('Property selection must belong to a selected object type.');
			}
			$validatedProperties = [];
			foreach ($properties as $property) {
				if (!is_string($property) || !isset($catalog[$type][$property])) {
					throw new \InvalidArgumentException(sprintf('Property "%s" is unavailable or sensitive.', (string) $property));
				}
				$validatedProperties[] = $property;
			}
			$validatedSelection[$type] = $validatedProperties;
		}

		return $validatedSelection;
	}
}
