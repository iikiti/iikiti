<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\ScheduledTaskProviderInterface;

final readonly class FirewallRulePackRefreshTaskProvider implements ScheduledTaskProviderInterface
{
	public function __construct(private FirewallRulePackSourceRegistry $sources)
	{
	}

	public function getKey(): string
	{
		return 'security.firewall.pack_refresh';
	}

	public function getName(): string
	{
		return 'Refresh firewall rule packs';
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
		return ['sources' => ['type' => 'array', 'label' => 'Registered source keys; empty refreshes all sources']];
	}

	public function validateOptions(array $options): array
	{
		if ([] === $options) {
			return ['sources' => $this->sources->getKeys()];
		}
		if ([] !== array_diff(array_keys($options), ['sources'])) {
			throw new \InvalidArgumentException('Firewall pack refresh contains unsupported options.');
		}
		$sources = $options['sources'] ?? null;
		if (!is_array($sources) || !array_is_list($sources)) {
			throw new \InvalidArgumentException('Firewall pack refresh sources must be a list of registered keys.');
		}
		$available = $this->sources->getKeys();
		if ([] === $sources) {
			return ['sources' => $available];
		}
		foreach ($sources as $source) {
			if (!is_string($source) || !in_array($source, $available, true)) {
				throw new \InvalidArgumentException('Firewall pack refresh contains an unknown source key.');
			}
		}
		if (count($sources) !== count(array_unique($sources))) {
			throw new \InvalidArgumentException('Firewall pack refresh source keys must be unique.');
		}

		return ['sources' => $sources];
	}

	public function getBatchConfiguration(array $options, int|string|null $siteId): array
	{
		if (null !== $siteId) {
			throw new \InvalidArgumentException('Firewall pack refresh schedules must be global.');
		}

		return ['sources' => $options['sources'] ?? $this->sources->getKeys()];
	}

	public function createMessages(array $options, int|string|null $siteId): iterable
	{
		if (null !== $siteId) {
			throw new \InvalidArgumentException('Firewall pack refresh schedules must be global.');
		}
		$timestamp = gmdate('YmdHi');
		foreach ($this->validateOptions($options)['sources'] as $source) {
			yield new BatchMessage(
				key: 'refresh.'.$source,
				type: FirewallRulePackRefreshHandler::TYPE,
				payload: ['source' => $source],
				summary: 'Refresh signed firewall rule pack '.$source,
				idempotencyKey: 'firewall-pack:'.$source.':'.$timestamp,
			);
		}
	}
}
