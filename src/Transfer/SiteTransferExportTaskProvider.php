<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\ScheduledTaskProviderInterface;

final class SiteTransferExportTaskProvider implements ScheduledTaskProviderInterface
{
	public function __construct(private readonly SiteTransferExportService $exports)
	{
	}

	public function getKey(): string { return 'site.transfer.export'; }
	public function getName(): string { return 'Export complete site'; }
	public function getScope(): string { return 'site'; }
	public function allowsOverlap(): bool { return false; }
	public function getOptionsSchema(): array { return []; }

	public function validateOptions(array $options): array
	{
		if ([] !== $options) {
			throw new \InvalidArgumentException('This scheduled export provider does not accept custom options.');
		}

		return [];
	}

	public function getBatchConfiguration(array $options, int|string|null $siteId): array
	{
		if (null === $siteId) {
			throw new \InvalidArgumentException('A site is required for scheduled exports.');
		}

		return ['siteTransfer' => $this->exports->buildPlan($siteId, [], [])['header']];
	}

	public function createMessages(array $options, int|string|null $siteId): iterable
	{
		if (null === $siteId) {
			throw new \InvalidArgumentException('A site is required for scheduled exports.');
		}

		return $this->exports->createMessages($this->exports->buildPlan($siteId, [], []));
	}
}
