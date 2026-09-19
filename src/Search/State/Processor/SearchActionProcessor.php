<?php

namespace iikiti\CMS\Search\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\SearchActionResource;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Service\IndexManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<mixed,mixed>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class SearchActionProcessor implements ProcessorInterface
{
	public function __construct(
		private SearchIndexRepository $indexRepository,
		private IndexManager $indexManager,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		$indexId = $uriVariables['id'] ?? null;
		$index = null;

		if (null !== $indexId) {
			$index = $this->indexRepository->find($indexId);
			if (null === $index) {
				throw new NotFoundHttpException(sprintf('Search index with id "%s" not found.', $indexId));
			}
		}

		return match ($operation->getName()) {
			'admin_search_index_create_table' => $this->createIndex($index),
			'admin_search_index_drop_table' => $this->dropIndex($index),
			'admin_search_index_rebuild_table' => $this->rebuildIndex($index),
			'admin_search_rebuild_all' => $this->rebuildAll(),
			default => $data,
		};
	}

	private function createIndex(SearchIndex $index): SearchActionResource
	{
		try {
			$this->indexManager->createIndex($index->getId());

			return $this->success('Created search index table for "%s".', $index->getSlug());
		} catch (\Throwable $e) {
			return $this->failure('Failed to create index: %s', $e->getMessage());
		}
	}

	private function dropIndex(SearchIndex $index): SearchActionResource
	{
		try {
			$this->indexManager->dropIndex($index->getId());

			return $this->success('Dropped search index table for "%s".', $index->getSlug());
		} catch (\Throwable $e) {
			return $this->failure('Failed to drop index: %s', $e->getMessage());
		}
	}

	private function rebuildIndex(SearchIndex $index): SearchActionResource
	{
		try {
			$this->indexManager->rebuildIndex($index->getId());

			return $this->success('Rebuilt search index for "%s".', $index->getSlug());
		} catch (\Throwable $e) {
			return $this->failure('Failed to rebuild index: %s', $e->getMessage());
		}
	}

	private function rebuildAll(): SearchActionResource
	{
		try {
			$count = $this->indexManager->rebuildAll();

			return $this->success('Rebuilt %d search index(es).', $count);
		} catch (\Throwable $e) {
			return $this->failure('Failed to rebuild indexes: %s', $e->getMessage());
		}
	}

	private function success(string $message, mixed ...$args): SearchActionResource
	{
		return new SearchActionResource(
			status: 'success',
			message: sprintf($message, ...$args),
		);
	}

	private function failure(string $message, mixed ...$args): SearchActionResource
	{
		return new SearchActionResource(
			status: 'error',
			message: sprintf($message, ...$args),
		);
	}
}
