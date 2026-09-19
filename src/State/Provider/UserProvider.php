<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\ApiResource\UserResource;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Provides user data for the admin UI.
 *
 * @implements ProviderInterface<UserResource|TraversablePaginator<UserResource>>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class UserProvider implements ProviderInterface
{
	public function __construct(
		private UserRepository $userRepository,
		#[Autowire('%api/default_pagination_page_size%')]
		private int $pageSize = 25,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$userId = $uriVariables['id'] ?? null;

		if (null !== $userId) {
			$user = $this->userRepository->find($userId);

			return null === $user ? null : UserResource::fromEntity($user);
		}

		$page = max(1, (int) ($context['filters']['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($context['filters']['itemsPerPage'] ?? $this->pageSize)));
		$offset = ($page - 1) * $itemsPerPage;

		$qb = $this->userRepository->createQueryBuilder('u');
		$qb->orderBy('u.id', 'ASC')
			->setMaxResults($itemsPerPage)
			->setFirstResult($offset);

		$results = $qb->getQuery()->getResult();
		$total = (int) $this->userRepository->count([]);

		$items = array_map(static fn (User $user): UserResource => UserResource::fromEntity($user), $results);

		return new TraversablePaginator(
			new \ArrayIterator($items),
			(float) $page,
			(float) $itemsPerPage,
			(float) $total,
		);
	}
}
