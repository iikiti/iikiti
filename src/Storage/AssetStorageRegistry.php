<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects {@see AssetStorageProviderInterface} drivers and resolves the configured
 * active storage backend (`ASSET_STORAGE_DRIVER`, defaulting to `local`).
 */
final class AssetStorageRegistry implements ResetInterface
{
	/** @var array<string,AssetStorageProviderInterface> */
	private array $providers = [];

	/** @var array<string,AssetStorageInterface> */
	private array $instances = [];

	private readonly string $activeDriver;

	/**
	 * @param iterable<AssetStorageProviderInterface> $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.asset_storage_provider')]
		iterable $providers = [],
		#[Autowire('%asset_storage_driver%')]
		string $activeDriver = LocalAssetStorage::DRIVER_NAME,
	) {
		foreach ($providers as $provider) {
			$name = strtolower(trim($provider->getDriverName()));
			if ('' === $name) {
				continue;
			}
			$this->providers[$name] = $provider;
		}
		$normalizedActive = strtolower(trim($activeDriver));
		$this->activeDriver = '' !== $normalizedActive ? $normalizedActive : LocalAssetStorage::DRIVER_NAME;
	}

	public function getActiveDriverName(): string
	{
		return $this->activeDriver;
	}

	public function getActiveStorage(): AssetStorageInterface
	{
		return $this->get($this->activeDriver);
	}

	public function get(string $driver): AssetStorageInterface
	{
		$normalized = strtolower(trim($driver));
		if (!isset($this->providers[$normalized])) {
			throw new \InvalidArgumentException(sprintf('Unknown asset storage driver "%s".', $driver));
		}

		if (isset($this->instances[$normalized])) {
			return $this->instances[$normalized];
		}

		$provider = $this->providers[$normalized];
		if (!$provider->isConfigured()) {
			throw new \RuntimeException(sprintf('Asset storage driver "%s" is not configured.', $normalized));
		}

		return $this->instances[$normalized] = $provider->createStorage();
	}

	public function has(string $driver): bool
	{
		return isset($this->providers[strtolower(trim($driver))]);
	}

	/**
	 * @return list<string>
	 */
	public function getDrivers(): array
	{
		return array_values(array_keys($this->providers));
	}

	public function reset(): void
	{
		$this->instances = [];
	}
}
