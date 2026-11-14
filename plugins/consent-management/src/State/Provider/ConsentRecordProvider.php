<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\Extension\ConsentManagement\ApiResource\ConsentRecordResource;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;
use iikiti\Extension\ConsentManagement\Repository\ConsentRecordRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides paginated consent records for the admin consent log, scoped to the
 * current site.
 */
#[AutoconfigureTag('api_platform.state_provider')]
final class ConsentRecordProvider implements ProviderInterface
{
	public function __construct(private readonly ConsentRecordRepository $repository)
	{
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$filters = $context['filters'] ?? [];

		$page = max(1, (int) ($filters['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($filters['itemsPerPage'] ?? 25)));
		$offset = ($page - 1) * $itemsPerPage;

		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		if (null === $site) {
			return new TraversablePaginator(
				new \ArrayIterator([]),
				(float) $page,
				(float) $itemsPerPage,
				0.0
			);
		}

		$results = $this->repository->findAllForSite($site, $page, $itemsPerPage);
		$total = (float) $this->repository->countForSite($site);

		$items = array_map(
			static fn (ConsentRecord $record): ConsentRecordResource => ConsentRecordResource::fromEntity($record),
			$results,
		);

		return new TraversablePaginator(
			new \ArrayIterator($items),
			(float) $page,
			(float) $itemsPerPage,
			$total,
		);
	}
}
