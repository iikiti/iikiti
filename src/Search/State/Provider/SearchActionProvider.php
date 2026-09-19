<?php

namespace iikiti\CMS\Search\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\SearchActionResource;
use iikiti\CMS\Search\Service\SearchService;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides metadata for search actions: available engines, object types,
 * and public filters.
 *
 * @implements ProviderInterface<SearchActionResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class SearchActionProvider implements ProviderInterface
{
	public function __construct(
		private SearchEngineRegistry $engineRegistry,
		private SearchService $searchService,
		private EntityManagerInterface $entityManager,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		return match ($operation->getName()) {
			'admin_search_engines' => $this->provideEngines(),
			'admin_search_object_types' => $this->provideObjectTypes(),
			'admin_search_public_filters' => $this->providePublicFilters($uriVariables['slug'] ?? ''),
			default => null,
		};
	}

	/**
	 * @return list<SearchActionResource>
	 */
	private function provideEngines(): array
	{
		$engines = $this->engineRegistry->describe();

		return array_map(
			static fn (array $e): SearchActionResource => new SearchActionResource(
				action: 'list_engines',
				status: 'ok',
				result: $e,
			),
			$engines
		);
	}

	/**
	 * @return list<SearchActionResource>
	 */
	private function provideObjectTypes(): array
	{
		$types = $this->discoverObjectTypes();

		return [new SearchActionResource(
			action: 'list_object_types',
			status: 'ok',
			result: ['types' => $types],
		)];
	}

	/**
	 * @return list<SearchActionResource>
	 */
	private function providePublicFilters(string $slug): array
	{
		$filters = $this->searchService->getPublicFilterNames($slug);

		return [new SearchActionResource(
			action: 'list_public_filters',
			status: 'ok',
			result: ['filters' => $filters],
			indexSlug: $slug,
		)];
	}

	/**
	 * Discover all DbObject subclass types from the Doctrine metadata factory.
	 *
	 * @return list<string>
	 */
	private function discoverObjectTypes(): array
	{
		$types = ['Application', 'Site', 'Page', 'User', 'Lexeme', 'ApiToken'];
		$seen = array_flip($types);

		foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
			$className = $metadata->getName();
			if (!is_subclass_of($className, \iikiti\CMS\Entity\DbObject::class)) {
				continue;
			}

			$short = (new \ReflectionClass($className))->getShortName();
			if (!isset($seen[$short])) {
				$types[] = $short;
				$seen[$short] = true;
			}
		}

		return $types;
	}
}
