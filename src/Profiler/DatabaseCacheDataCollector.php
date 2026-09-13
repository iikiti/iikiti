<?php

namespace iikiti\CMS\Profiler;

use iikiti\CMS\Cache\StrategyMetadata;
use iikiti\CMS\Service\CacheState;
use iikiti\CMS\Service\DatabaseCacheManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Symfony Profiler data collector for the database query caching layer.
 *
 * Shows the active strategy, the per-request CacheState and the registered
 * strategies. Pool-level hit/miss statistics are surfaced by the built-in
 * Cache profiler panel; this collector focuses on the iikiti cache layer
 * (strategy resolution + backend adapter).
 *
 * Registered only in dev/test environments.
 */
final class DatabaseCacheDataCollector extends DataCollector
{
	/** @var array<string,string> */
	private const TRACKED_ENTITIES = [
		'iikiti\\CMS\\Entity\\Object\\Site' => 'Site',
		'iikiti\\CMS\\Entity\\Object\\User' => 'User',
		'iikiti\\CMS\\Entity\\Object\\Application' => 'Application',
		'iikiti\\CMS\\Entity\\Object\\Page' => 'Page',
		'iikiti\\CMS\\Entity\\Object\\Lexeme' => 'Lexeme',
		'iikiti\\CMS\\Entity\\Object\\ApiToken' => 'ApiToken',
	];

	public function __construct(
		private readonly CacheState $cacheState,
		private readonly DatabaseCacheManager $cacheManager
	) {
	}

	public function getName(): string
	{
		return 'iikiti.database_cache';
	}

	/**
	 * Snapshot lightweight data at the end of the request so the panel can
	 * render without re-invoking services.
	 */
	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		$strategy = $this->cacheManager->getStrategy();
		$pool = $this->cacheManager->getPool();
		$this->data = [
			'strategy_name' => $strategy->getName(),
			'strategy_label' => $strategy->getLabel(),
			'enabled' => $this->cacheState->isEnabled(),
			'available_strategies' => array_map(
				static fn (StrategyMetadata $metadata): array => [
					'name' => $metadata->name,
					'label' => $metadata->label,
				],
				$this->cacheManager->getAvailableStrategies()
			),
			'pool_class' => null !== $pool ? $this->safeClassName($pool) : 'n/a',
			'pool_stats' => $this->cacheManager->getPoolStats(),
			'generations' => $this->sampleGenerations(),
		];
	}

	/**
	 * @return array{name:string,label:string,enabled:bool}
	 */
	public function strategy(): array
	{
		return [
			'name' => $this->data['strategy_name'] ?? 'none',
			'label' => $this->data['strategy_label'] ?? 'None',
			'enabled' => (bool) ($this->data['enabled'] ?? false),
		];
	}

	/**
	 * @return list<array{name:string,label:string}>
	 */
	public function availableStrategies(): array
	{
		return array_values($this->data['available_strategies'] ?? []);
	}

	/**
	 * @return array<string,int>
	 */
	public function generations(): array
	{
		return $this->data['generations'] ?? [];
	}

	public function poolClass(): string
	{
		return $this->data['pool_class'] ?? 'n/a';
	}

	/**
	 * @return array<string,int>|null
	 */
	public function poolStats(): ?array
	{
		return $this->data['pool_stats'] ?? null;
	}

	public const TEMPLATE = 'bundles/Profiler/cache_database.html.twig';

	/**
	 * Path to the Twig template backing this collector's profiler panel.
	 * The matching service definition declares the same template via the
	 * `data_collector` tag for completeness.
	 */
	public static function getTemplate(): string
	{
		return static::TEMPLATE;
	}

	public function reset(): void
	{
		$this->data = [];
	}

	/**
	 * @return array<string,int>
	 */
	private function sampleGenerations(): array
	{
		$generations = [];
		foreach (array_keys(self::TRACKED_ENTITIES) as $entityClass) {
			$generations[$entityClass] = $this->cacheManager->getGeneration($entityClass);
		}

		return $generations;
	}

	private function safeClassName(object $object): string
	{
		try {
			return get_class($object);
		} catch (\Throwable) {
			return 'unknown';
		}
	}
}
