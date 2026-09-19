<?php

namespace iikiti\CMS\Search\Service;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Exception\IndexNotFoundException;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Manages search index lifecycle: creation, drop, rebuild and sync.
 *
 * This service acts as the orchestration layer between SearchIndex
 * configurations and the concrete {@see \iikiti\CMS\Search\Strategy\SearchEngineInterface}
 * implementations. It resolves the correct engine for each config and delegates
 * the actual DDL/data operations.
 */
final class IndexManager
{
	/** @var array<string,bool> */
	private array $engineExtensionsApplied = [];

	public function __construct(
		private readonly SearchEngineRegistry $engineRegistry,
		private readonly SearchIndexRepository $indexRepository,
		#[Autowire('%iikiti_search.auto_index%')]
		private readonly bool $autoIndex = true,
	) {
	}

	/**
	 * Create the search index table for a configuration.
	 */
	public function createIndex(int|string $indexId): void
	{
		$index = $this->indexRepository->find($indexId);
		if (null === $index) {
			throw IndexNotFoundException::fromId($indexId);
		}

		$engine = $this->engineRegistry->resolveForConfig($index);
		$this->ensureExtensions($engine->getName());
		$engine->createIndex($index);
	}

	/**
	 * Drop the search index table for a configuration.
	 */
	public function dropIndex(int|string $indexId): void
	{
		$index = $this->indexRepository->find($indexId);
		if (null === $index) {
			throw IndexNotFoundException::fromId($indexId);
		}

		$engine = $this->engineRegistry->resolveForConfig($index);
		$engine->dropIndex($index);
	}

	/**
	 * Rebuild the index from source data.
	 */
	public function rebuildIndex(int|string $indexId): void
	{
		$index = $this->indexRepository->find($indexId);
		if (null === $index) {
			throw IndexNotFoundException::fromId($indexId);
		}

		if (!$index->isEnabled()) {
			return;
		}

		$engine = $this->engineRegistry->resolveForConfig($index);
		$this->ensureExtensions($engine->getName());
		$engine->rebuildIndex($index);
	}

	/**
	 * Rebuild all enabled indexes.
	 *
	 * @param list<int|string>|null $siteIds
	 */
	public function rebuildAll(?array $siteIds = null): int
	{
		$indexes = $this->findEnabledIndexes($siteIds);
		$count = 0;

		foreach ($indexes as $index) {
			$engine = $this->engineRegistry->resolveForConfig($index);
			$this->ensureExtensions($engine->getName());
			$engine->rebuildIndex($index);
			++$count;
		}

		return $count;
	}

	/**
	 * Synchronize: ensure all enabled indexes have their tables created.
	 */
	public function syncIndexes(): int
	{
		$indexes = $this->indexRepository->findBy(['isEnabled' => true]);
		$count = 0;

		foreach ($indexes as $index) {
			$engine = $this->engineRegistry->resolveForConfig($index);
			$this->ensureExtensions($engine->getName());
			$engine->createIndex($index);
			++$count;
		}

		return $count;
	}

	/**
	 * Reindex a single object across all enabled indexes that cover its type.
	 */
	public function reindexObject(\iikiti\CMS\Entity\DbObject $object, ?string $objectType = null): void
	{
		if (!$this->autoIndex) {
			return;
		}

		if (null === $objectType) {
			$objectType = $object->getType();
		}

		$indexes = $this->findEnabledIndexes(null);

		foreach ($indexes as $index) {
			$engine = $this->engineRegistry->resolveForConfig($index);
			$engine->reindexObject($index, $object);
		}
	}

	/**
	 * Remove an object from all enabled indexes.
	 */
	public function removeFromIndexes(\iikiti\CMS\Entity\DbObject $object): void
	{
		if (!$this->autoIndex) {
			return;
		}

		$indexes = $this->findEnabledIndexes(null);

		foreach ($indexes as $index) {
			if ($index->isSystemLocked()) {
				continue;
			}

			$engine = $this->engineRegistry->resolveForConfig($index);
			$engine->removeFromIndex($index, $object);
		}
	}

	/**
	 * @param ?list<int|string> $siteIds
	 *
	 * @return list<SearchIndex>
	 */
	private function findEnabledIndexes(?array $siteIds): array
	{
		if (null === $siteIds) {
			/** @var list<SearchIndex> $result */
			$result = $this->indexRepository->findBy(['isEnabled' => true]);

			return $result;
		}

		/** @var list<SearchIndex> $result */
		$result = $this->indexRepository->createQueryBuilder('i')->
			where('i.isEnabled = :enabled')->
			andWhere('i.siteId IS NULL OR i.siteId IN (:siteIds)')->
			setParameter('enabled', true)->
			setParameter('siteIds', $siteIds)->
			getQuery()->
			getResult();

		return $result;
	}

	private function ensureExtensions(string $engineName): void
	{
		if (isset($this->engineExtensionsApplied[$engineName])) {
			return;
		}

		// Extensions like pg_trgm are created lazily by the engine itself
		$this->engineExtensionsApplied[$engineName] = true;
	}

	public function isAutoIndex(): bool
	{
		return $this->autoIndex;
	}
}
