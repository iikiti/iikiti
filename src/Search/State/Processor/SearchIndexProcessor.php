<?php

namespace iikiti\CMS\Search\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\SearchIndexResource;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchIndexField;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Exception\SystemLockedException;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Service\IndexManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Handles CRUD and lifecycle operations on search index configurations.
 *
 * Enforces system-locked protection: system-locked indexes (e.g. the admin
 * search config) cannot be deleted or have their engine changed. Other fields
 * remain editable.
 */
/**
 * @implements ProcessorInterface<SearchIndexResource,mixed>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class SearchIndexProcessor implements ProcessorInterface
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
		return match ($operation->getName()) {
			'admin_search_index_create' => $this->create($data),
			'admin_search_index_update' => $this->update($data, $uriVariables['id'] ?? null),
			'admin_search_index_delete' => $this->delete($uriVariables['id'] ?? null),
			default => $data,
		};
	}

	private function create(SearchIndexResource $resource): SearchIndexResource
	{
		if (SearchIndexType::Custom !== SearchIndexType::from($resource->type)) {
			throw new UnprocessableEntityHttpException(sprintf('Cannot create %s type index via API. Use the config:init command for defaults.', $resource->type));
		}

		$index = new SearchIndex(
			$resource->slug,
			$resource->name,
			SearchIndexType::from($resource->type),
			$resource->engine,
			$resource->language,
		);

		$index->setDescription($resource->description);
		$index->setEnabled($resource->isEnabled);
		$index->setSiteId($resource->siteId);
		$index->setOptions($resource->options);

		$this->syncFields($index, $resource->fields);

		$this->entityManager->persist($index);
		$this->entityManager->flush();

		return SearchIndexResource::fromEntity($index);
	}

	private function update(SearchIndexResource $resource, int|string|null $id): SearchIndexResource
	{
		$index = $this->indexRepository->find($id);
		if (null === $index) {
			return $resource;
		}

		$index->setName($resource->name);
		$index->setDescription($resource->description);
		$index->setEnabled($resource->isEnabled);
		$index->setSiteId($resource->siteId);
		$index->setLanguage($resource->language);
		$index->setOptions($resource->options);

		if ($index->isSystemLocked()) {
			try {
				$index->setEngine($resource->engine);
			} catch (SystemLockedException) {
				throw new ConflictHttpException(sprintf('Search index "%s" is system-locked and its engine cannot be changed.', $index->getSlug()));
			}
		} else {
			$index->setEngine($resource->engine);
		}

		$this->syncFields($index, $resource->fields);

		$this->entityManager->persist($index);
		$this->entityManager->flush();

		return SearchIndexResource::fromEntity($index);
	}

	private function delete(int|string|null $id): null
	{
		$index = $this->indexRepository->find($id);
		if (null === $index) {
			return null;
		}

		if ($index->isSystemLocked()) {
			throw new ConflictHttpException(sprintf('Search index "%s" is system-locked and cannot be deleted.', $index->getSlug()));
		}

		try {
			$this->indexManager->dropIndex($index->getId());
		} catch (\Throwable) {
			// Log but don't fail — the config can still be removed
		}

		$this->entityManager->remove($index);
		$this->entityManager->flush();

		return null;
	}

	/**
	 * @param list<array{name:string, source_type:string, source:string, weight:int}> $fieldData
	 */
	private function syncFields(SearchIndex $index, array $fieldData): void
	{
		$existing = [];
		foreach ($index->getFields() as $field) {
			$existing[$field->getName()] = $field;
		}

		$updatedNames = [];

		foreach ($fieldData as $fieldInfo) {
			$name = $fieldInfo['name'];
			$updatedNames[] = $name;

			$field = $existing[$name] ?? new SearchIndexField(
				$fieldInfo['name'],
				SearchFieldSourceType::from($fieldInfo['source_type']),
				$fieldInfo['source'],
			);

			$field->setName($fieldInfo['name']);
			$field->setSourceType(SearchFieldSourceType::from($fieldInfo['source_type']));
			$field->setSource($fieldInfo['source']);
			$field->setWeight($fieldInfo['weight']);
			$field->setSearchIndex($index);

			if (!isset($existing[$name])) {
				$index->addField($field);
			}
		}

		foreach ($index->getFields() as $field) {
			if (!in_array($field->getName(), $updatedNames, true)) {
				$index->removeField($field);
			}
		}
	}
}
