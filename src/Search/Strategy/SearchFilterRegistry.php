<?php

namespace iikiti\CMS\Search\Strategy;

use iikiti\CMS\Search\Entity\SearchFilter;
use iikiti\CMS\Search\Enum\SearchFilterVisibility;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Registry of available search filters.
 *
 * Filters are collected at compile time from services tagged with
 * `iikiti.search_filter`, and may also be registered at runtime via
 * {@see register()}. The registry resolves which filters are active for a
 * given index and request context based on visibility and role requirements.
 */
final class SearchFilterRegistry
{
	/** @var array<string,SearchFilterInterface> */
	private array $filters = [];

	/**
	 * @param iterable<SearchFilterInterface> $taggedFilters
	 */
	public function __construct(
		#[AutowireIterator('iikiti.search_filter')]
		private readonly iterable $taggedFilters,
		private readonly ?AuthorizationCheckerInterface $authChecker = null,
		private readonly ?TokenStorageInterface $tokenStorage = null,
	) {
		foreach ($taggedFilters as $filter) {
			$this->register($filter);
		}
	}

	public function register(SearchFilterInterface $filter): void
	{
		$this->filters[$filter->getName()] = $filter;
	}

	public function unregister(string $name): void
	{
		unset($this->filters[$name]);
	}

	public function has(string $name): bool
	{
		return isset($this->filters[$name]);
	}

	public function get(string $name): ?SearchFilterInterface
	{
		return $this->filters[$name] ?? null;
	}

	/**
	 * @return array<string,SearchFilterInterface>
	 */
	public function all(): array
	{
		return $this->filters;
	}

	/**
	 * Resolve active filters for a search index, given the entity-based
	 * SearchFilter configurations and the current user's roles.
	 *
	 * @param list<SearchFilter> $filterConfigs
	 *
	 * @return list<array{config:SearchFilter, implementation:SearchFilterInterface|null}>
	 */
	public function resolveForContext(array $filterConfigs, bool $includeAdmin = false): array
	{
		$resolved = [];
		$token = null !== $this->tokenStorage ? $this->tokenStorage->getToken() : null;

		foreach ($filterConfigs as $config) {
			if (!$config->isEnabled()) {
				continue;
			}

			$visibility = $config->getVisibility();

			if (SearchFilterVisibility::AdminOnly === $visibility && !$includeAdmin) {
				continue;
			}

			if (SearchFilterVisibility::PublicFrontend === $visibility) {
				// Visible to everyone
			}

			if (SearchFilterVisibility::RoleRestricted === $visibility) {
				if (null === $this->authChecker || null === $token) {
					continue;
				}

				foreach ($config->getRequiredRoles() as $role) {
					if (!$this->authChecker->isGranted($role, $token)) {
						continue 2;
					}
				}
			}

			$implementation = $config->getHook() ?
				$this->get(substr($config->getHook(), 0, strpos($config->getHook(), '::') ?: 0)) :
				null;

			if (null === $implementation && null !== $config->getHook()) {
				$hookName = $config->getHook();
				$pos = strpos($hookName, '::');
				if (false !== $pos) {
					$hookName = substr($hookName, 0, $pos);
				}
				$implementation = $this->get($hookName);
			}

			$resolved[] = [
				'config' => $config,
				'implementation' => $implementation,
			];
		}

		return $resolved;
	}
}
