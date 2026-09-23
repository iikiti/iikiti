<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\ApiResource\TemplateResource;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Repository\Object\TemplateRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides template data for the admin UI (`/admin/templates`).
 *
 * @implements ProviderInterface<TemplateResource|TraversablePaginator<TemplateResource>>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class TemplateStateProvider implements ProviderInterface
{
	public function __construct(
		private TemplateRepository $repository,
		#[Autowire('%api/default_pagination_page_size%')]
		private int $pageSize = 25,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$templateId = $uriVariables['id'] ?? null;

		if (null !== $templateId) {
			$template = $this->repository->find($templateId);

			return null === $template ? null : TemplateResource::fromEntity($template);
		}

		$page = max(1, (int) ($context['filters']['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($context['filters']['itemsPerPage'] ?? $this->pageSize)));
		$offset = ($page - 1) * $itemsPerPage;

		$qb = $this->repository->createQueryBuilder('t')
			->orderBy('t.id', 'ASC')
			->setMaxResults($itemsPerPage)
			->setFirstResult($offset);

		$results = $qb->getQuery()->getResult();
		$total = (int) $this->repository->count([]);

		/** @var list<Template> $results */
		$items = array_map(static fn (Template $t): TemplateResource => TemplateResource::fromEntity($t), $results);

		return new TraversablePaginator(
			new \ArrayIterator($items),
			(float) $page,
			(float) $itemsPerPage,
			(float) $total,
		);
	}
}
