<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/** Server-side cache for explicitly shared, independently rendered widgets. */
final class RenderedFragmentCache
{
	private const CMS_DEPENDENCY_TAG = 'iikiti_rendered_fragments_cms';

	public function __construct(
		#[Autowire(service: 'cache.rendered_fragments')]
		private readonly TagAwareCacheInterface $cache,
		private readonly EntityManagerInterface $entityManager,
		private readonly Security $security,
		#[Autowire('%iikiti_cache.rendered_fragment_ttl%')]
		private readonly int $ttl = 300,
		private readonly ?AsyncResponseDataCollector $profiler = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * @param array<string,mixed> $node
	 * @param callable(RenderCacheTagCollector):array{html:string,styles:list<array{id:?string,css:string}>} $render
	 * @return array{html:string,styles:list<array{id:?string,css:string}>}
	 */
	public function remember(array $node, BlockRenderContext $context, string $revision, string $fragmentId, callable $render): array
	{
		$scope = $this->scopeFor($node, $context);
		if (null === $scope) {
			return $render(new RenderCacheTagCollector());
		}

		$siteId = (string) $context->site?->getId();
		$key = 'iikiti_rendered_widget_'.hash('sha256', serialize([
			$siteId,
			$revision,
			$fragmentId,
			$scope,
			$context->request?->getRequestUri(),
		]));
		$attempt = new RenderedFragmentCacheAttempt();

		try {
			$value = $this->cache->get($key, function (ItemInterface $item) use ($render, $attempt): array {
				$tags = new RenderCacheTagCollector();
				try {
					$result = $render($tags);
					$attempt->result = $result;
				} catch (\Throwable $exception) {
					$attempt->renderFailure = $exception;
					throw $exception;
				}

				$item->expiresAfter(max(1, $this->ttl));
				$item->tag(array_values(array_unique([self::CMS_DEPENDENCY_TAG, ...$tags->all()])));

				return $result;
			});

			if (null !== $attempt->result) {
				$this->profiler?->recordRenderedFragmentCache(false);

				return $attempt->result;
			}

			$cached = $this->validValue($value);
			if (null !== $cached) {
				$this->profiler?->recordRenderedFragmentCache(true);

				return $cached;
			}

			$this->cache->delete($key);
		} catch (\Throwable $exception) {
			if (null !== $attempt->renderFailure) {
				throw $attempt->renderFailure;
			}
			if (null !== $attempt->result) {
				$this->logger?->warning('Rendered fragment cache write failed; using rendered output.', ['exception' => $exception]);
				$this->profiler?->recordRenderedFragmentCache(false);

				return $attempt->result;
			}
			$this->logger?->warning('Rendered fragment cache read failed; rendering without cache.', ['exception' => $exception]);
		}

		$this->profiler?->recordRenderedFragmentCache(false);

		return $render(new RenderCacheTagCollector());
	}

	public function invalidateCmsDependencies(): void
	{
		$this->invalidateTags([self::CMS_DEPENDENCY_TAG]);
	}

	/** @param list<mixed> $tags */
	public function invalidateTags(array $tags): void
	{
		$tags = array_values(array_filter($tags, static fn (mixed $tag): bool =>
			is_string($tag) && '' !== $tag && strlen($tag) <= 128 && 1 === preg_match('/^[a-zA-Z0-9_.-]+$/D', $tag)));
		if ([] === $tags) {
			return;
		}

		try {
			$this->cache->invalidateTags($tags);
		} catch (\Throwable $exception) {
			$this->logger?->warning('Rendered fragment cache tag invalidation failed.', ['exception' => $exception]);
		}
	}

	/** @param array<string,mixed> $node */
	private function scopeFor(array $node, BlockRenderContext $context): ?string
	{
		if ($context->editorMode || $context->canEdit || null === $context->site) {
			return null;
		}

		$siteId = (string) $context->site->getId();
		if ('' === $siteId || '0' === $siteId) {
			return null;
		}

		$mode = $node['cacheMode'] ?? 'none';
		if ('global' === $mode) {
			return 'global';
		}
		if ('user_group' !== $mode) {
			return null;
		}

		$groupId = $node['cacheGroupId'] ?? null;
		if ((!is_string($groupId) && !is_int($groupId)) || 1 !== preg_match('/^[1-9][0-9]{0,18}$/D', (string) $groupId)) {
			return null;
		}

		$user = $this->security->getUser();
		if (!$user instanceof User) {
			return null;
		}

		$group = $this->entityManager->find(UserGroup::class, (string) $groupId);
		if (!$group instanceof UserGroup
			|| !in_array((string) $group->getSiteId(), ['0', $siteId], true)
			|| !$this->userBelongsToGroup($user, (string) $groupId)) {
			return null;
		}

		return 'user_group:'.$groupId;
	}

	private function userBelongsToGroup(User $user, string $groupId): bool
	{
		$groups = $user->getProperties()->get('groups')?->getValue();
		if (!is_array($groups)) {
			return false;
		}

		foreach ($groups as $memberGroupId) {
			if ((is_string($memberGroupId) || is_int($memberGroupId)) && $groupId === (string) $memberGroupId) {
				return true;
			}
		}

		return false;
	}

	/** @return array{html:string,styles:list<array{id:?string,css:string}>}|null */
	private function validValue(mixed $value): ?array
	{
		if (!is_array($value) || !is_string($value['html'] ?? null) || !is_array($value['styles'] ?? null) || !array_is_list($value['styles'])) {
			return null;
		}

		$styles = [];
		foreach ($value['styles'] as $style) {
			if (!is_array($style) || !array_key_exists('id', $style) || !is_string($style['css'] ?? null)) {
				return null;
			}
			if (null !== $style['id'] && !is_string($style['id'])) {
				return null;
			}
			$styles[] = ['id' => $style['id'], 'css' => $style['css']];
		}

		return ['html' => $value['html'], 'styles' => $styles];
	}
}
