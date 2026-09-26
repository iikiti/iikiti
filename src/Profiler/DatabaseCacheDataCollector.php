<?php

namespace iikiti\CMS\Profiler;

use iikiti\CMS\Cache\StrategyMetadata;
use iikiti\CMS\Service\CacheState;
use iikiti\CMS\Service\DatabaseCacheManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Profiler integration for the database query-cache layer.
 *
 * This is a *tabless* data collector: it is collected and stored by the profiler
 * (so its snapshot is reachable from other panels via `profile.getCollector(
 * 'iikiti.database_cache')`) but it carries no `template` tag, so
 * Symfony\Component\WebProfilerBundle\Profiler\TemplateManager skips it when
 * building the profiler tab list (`getNames()` discards null templates). Its data
 * is therefore surfaced exclusively inside the built-in Cache panel, via the
 * overridden `templates/bundles/WebProfilerBundle/Collector/cache.html.twig`.
 *
 * Collected data is snapshotted into $this->data during collect() and read back
 * from $this->data at render time. This is render-safe because
 * DataCollector::__serialize() only serializes $this->data; the service
 * dependencies (CacheState, DatabaseCacheManager) are not serialized and are not
 * accessed by any of the getters below.
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
		private readonly DatabaseCacheManager $cacheManager,
	) {
	}

	public function getName(): string
	{
		return 'iikiti.database_cache';
	}

	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		$strategy = $this->cacheManager->getStrategy();

		$this->data = [
			'strategy' => [
				'name' => $strategy->getName(),
				'label' => $strategy->getLabel(),
				'enabled' => $this->cacheState->isEnabled(),
			],
			'generations' => $this->sampleGenerations(),
			'available_strategies' => array_map(
				static fn (StrategyMetadata $metadata): array => [
					'name' => $metadata->name,
					'label' => $metadata->label,
				],
				$this->cacheManager->getAvailableStrategies(),
			),
		];
	}

	/**
	 * @return array{name:string,label:string,enabled:bool}
	 */
	public function ikitiStrategy(): array
	{
		$strategy = $this->data['strategy'] ?? null;

		return is_array($strategy) ? $strategy : ['name' => 'none', 'label' => 'None', 'enabled' => false];
	}

	/**
	 * @return array<string,int>
	 */
	public function ikitiGenerations(): array
	{
		$generations = $this->data['generations'] ?? null;

		return is_array($generations) ? $generations : [];
	}

	/**
	 * @return list<array{name:string,label:string}>
	 */
	public function ikitiAvailableStrategies(): array
	{
		$strategies = $this->data['available_strategies'] ?? null;

		return array_values(is_array($strategies) ? $strategies : []);
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
}
