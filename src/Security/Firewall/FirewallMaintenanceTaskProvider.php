<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\ScheduledTaskProviderInterface;

/**
 * Scheduled cleanup that purges expired firewall IP bans and expired managed IP rules.
 */
final readonly class FirewallMaintenanceTaskProvider implements ScheduledTaskProviderInterface
{
	public const KEY = 'security.firewall.maintenance';

	public function getKey(): string
	{
		return self::KEY;
	}

	public function getName(): string
	{
		return 'Purge expired firewall bans and IP rules';
	}

	public function getScope(): string
	{
		return 'global';
	}

	public function allowsOverlap(): bool
	{
		return false;
	}

	public function getOptionsSchema(): array
	{
		return [];
	}

	public function validateOptions(array $options): array
	{
		if ([] !== $options) {
			throw new \InvalidArgumentException('Firewall maintenance does not accept options.');
		}

		return [];
	}

	public function getBatchConfiguration(array $options, int|string|null $siteId): array
	{
		$this->assertGlobal($siteId);

		return [];
	}

	public function createMessages(array $options, int|string|null $siteId): iterable
	{
		$this->assertGlobal($siteId);
		yield new BatchMessage(
			key: 'maintenance.expired',
			type: FirewallMaintenanceHandler::TYPE,
			payload: [],
			summary: 'Purge expired firewall bans and IP rules',
			idempotencyKey: 'firewall-maintenance:'.gmdate('YmdHi'),
		);
	}

	private function assertGlobal(int|string|null $siteId): void
	{
		if (null !== $siteId) {
			throw new \InvalidArgumentException('Firewall maintenance schedules must be global.');
		}
	}
}
