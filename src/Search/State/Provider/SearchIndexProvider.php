<?php

namespace iikiti\CMS\Search\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\SearchIndexResource;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides {@see SearchIndexResource} data for the admin API.
 *
 * @implements ProviderInterface<SearchIndexResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class SearchIndexProvider implements ProviderInterface
{
	public function __construct(
		private SearchIndexRepository $indexRepository,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$id = $uriVariables['id'] ?? null;

		if (null !== $id) {
			$index = $this->indexRepository->find($id);
			if (null === $index) {
				return null;
			}

			return SearchIndexResource::fromEntity($index);
		}

		/** @var list<SearchIndex> $indexes */
		$indexes = $this->indexRepository->findAll();

		usort($indexes, static fn (SearchIndex $a, SearchIndex $b): int => strcmp($a->getName(), $b->getName()));

		return array_map(SearchIndexResource::fromEntity(...), $indexes);
	}
}
