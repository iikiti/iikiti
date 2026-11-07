<?php

namespace iikiti\CMS\Search\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\SearchActionResource;
use iikiti\CMS\Search\Entity\SearchConfigGroup;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchIndexField;
use iikiti\CMS\Search\Entity\SiteGroup;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use iikiti\CMS\Search\Enum\SearchIndexType;
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
		private EntityManagerInterface $entityManager,
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
			'admin_search_rebuild_all' => $this->rebuildAll($data),
			'admin_search_config_init' => $this->initializeDefaults(),
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

	private function rebuildAll(mixed $data): SearchActionResource
	{
		try {
			$siteIds = $data instanceof SearchActionResource ? $data->siteIds : null;
			$count = $this->indexManager->rebuildAll($siteIds);

			return new SearchActionResource(
				status: 'success',
				message: sprintf('Rebuilt %d search index(es).', $count),
				result: ['count' => $count],
			);
		} catch (\Throwable $e) {
			return $this->failure('Failed to rebuild indexes: %s', $e->getMessage());
		}
	}

	private function initializeDefaults(): SearchActionResource
	{
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();

		try {
			$frontend = $this->createDefaultIndex(
				'frontend',
				'Front-end Search',
				SearchIndexType::Frontend,
				'Default front-end search index for public site search.',
				[
					['title', SearchFieldSourceType::Property, 'title', 4],
					['content', SearchFieldSourceType::Property, 'content', 3],
					['body', SearchFieldSourceType::Property, 'body', 3],
					['excerpt', SearchFieldSourceType::Property, 'excerpt', 2],
					['tags', SearchFieldSourceType::Property, 'tags', 2],
					['slug', SearchFieldSourceType::Property, 'slug', 1],
					['type', SearchFieldSourceType::Column, 'type', 1],
				],
			);
			$admin = $this->createDefaultIndex(
				'admin',
				'Administration Search',
				SearchIndexType::Admin,
				'System-level administration search index. System-locked: cannot be deleted, but fields can be managed.',
				[
					['title', SearchFieldSourceType::Property, 'title', 4],
					['content', SearchFieldSourceType::Property, 'content', 3],
					['body', SearchFieldSourceType::Property, 'body', 3],
					['excerpt', SearchFieldSourceType::Property, 'excerpt', 2],
					['tags', SearchFieldSourceType::Property, 'tags', 2],
					['slug', SearchFieldSourceType::Property, 'slug', 1],
					['type', SearchFieldSourceType::Column, 'type', 2],
					['id', SearchFieldSourceType::Column, 'id', 1],
					['created_date', SearchFieldSourceType::Column, 'created_date', 1],
					['keywords', SearchFieldSourceType::Property, 'keywords', 2],
					['meta_description', SearchFieldSourceType::Property, 'meta_description', 2],
				],
			);

			$this->entityManager->flush();

			$group = new SearchConfigGroup('default', 'Default Search Configuration');
			$group->setDescription('Default search configuration group containing frontend and admin search indexes.');
			$group->setSystem(true);
			$group->setSearchIndexIds([$frontend->getId(), $admin->getId()]);
			$this->entityManager->persist($group);

			$siteGroup = new SiteGroup('default', 'All Sites');
			$siteGroup->setDescription('Default site group containing all sites.');
			$siteGroup->setSystem(true);
			$this->entityManager->persist($siteGroup);
			$this->entityManager->flush();
			$connection->commit();

			return $this->success('Default search configurations created successfully.');
		} catch (\Throwable $e) {
			$connection->rollBack();

			return $this->failure('%s', $e->getMessage());
		}
	}

	/**
	 * @param list<array{0:string,1:SearchFieldSourceType,2:string,3:int}> $fields
	 */
	private function createDefaultIndex(
		string $slug,
		string $name,
		SearchIndexType $type,
		string $description,
		array $fields,
	): SearchIndex {
		$index = new SearchIndex($slug, $name, $type, 'postgresql', 'english');
		$index->setDescription($description);
		$index->setSystemLocked(SearchIndexType::Admin === $type);

		foreach ($fields as $position => [$fieldName, $sourceType, $source, $weight]) {
			$field = new SearchIndexField($fieldName, $sourceType, $source);
			$field->setWeight($weight);
			$field->setPosition($position + 1);
			$index->addField($field);
		}

		$this->entityManager->persist($index);

		return $index;
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
