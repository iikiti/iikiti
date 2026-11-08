<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class RenderedFragmentCacheTest extends TestCase
{
	public function testGlobalCacheIsSharedWithinButNotAcrossSites(): void
	{
		$service = $this->cacheService(null);
		$node = ['cacheMode' => 'global'];
		$renderCount = 0;
		$render = static function ($tags) use (&$renderCount): array {
			++$renderCount;
			$tags->add('plugin.catalog');

			return ['html' => '<p>shared</p>', 'styles' => []];
		};

		$first = $service->remember($node, $this->context(11), str_repeat('a', 64), str_repeat('b', 64), $render);
		$second = $service->remember($node, $this->context(11), str_repeat('a', 64), str_repeat('b', 64), $render);
		$otherSite = $service->remember($node, $this->context(12), str_repeat('a', 64), str_repeat('b', 64), $render);

		self::assertSame($first, $second);
		self::assertSame($first, $otherSite);
		self::assertSame(2, $renderCount);
	}

	public function testUserGroupEntryIsSharedOnlyWithMembersAndDoesNotChangeVisibility(): void
	{
		$group = new UserGroup();
		$group->setSiteId(11);
		$this->setId($group, 27);
		$currentUser = $this->userWithGroups([27]);
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturnCallback(static function () use (&$currentUser) {
			return $currentUser;
		});
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('find')->willReturn($group);
		$service = $this->cacheService($security, $entityManager);
		$node = ['cacheMode' => 'user_group', 'cacheGroupId' => '27'];
		$renderCount = 0;
		$render = static function () use (&$renderCount): array {
			++$renderCount;

			return ['html' => '<p>group content</p>', 'styles' => []];
		};

		$firstMember = $service->remember($node, $this->context(11), str_repeat('c', 64), str_repeat('d', 64), $render);
		$secondMember = $service->remember($node, $this->context(11), str_repeat('c', 64), str_repeat('d', 64), $render);
		$currentUser = $this->userWithGroups([]);
		$nonMember = $service->remember($node, $this->context(11), str_repeat('c', 64), str_repeat('d', 64), $render);

		self::assertSame($firstMember, $secondMember);
		self::assertSame($firstMember, $nonMember);
		self::assertSame(2, $renderCount);
	}

	public function testNoneAndEditorRenderingBypassFragmentCache(): void
	{
		$service = $this->cacheService(null);
		$renderCount = 0;
		$render = static function () use (&$renderCount): array {
			++$renderCount;

			return ['html' => '<p>output</p>', 'styles' => []];
		};

		$service->remember(['cacheMode' => 'none'], $this->context(11), str_repeat('e', 64), str_repeat('f', 64), $render);
		$service->remember(['cacheMode' => 'global'], $this->context(11, editorMode: true), str_repeat('e', 64), str_repeat('f', 64), $render);
		$service->remember(['cacheMode' => 'global'], $this->context(11, canEdit: true), str_repeat('e', 64), str_repeat('f', 64), $render);

		self::assertSame(3, $renderCount);
	}

	public function testCmsDependencyInvalidationExpiresCachedFragments(): void
	{
		$service = $this->cacheService(null);
		$node = ['cacheMode' => 'global'];
		$renderCount = 0;
		$context = $this->context(11);
		$revision = str_repeat('1', 64);
		$fragmentId = str_repeat('2', 64);
		$render = static function ($tags) use (&$renderCount): array {
			++$renderCount;
			$tags->add('widget.query_results');

			return ['html' => '<p>fresh</p>', 'styles' => []];
		};

		$service->remember($node, $context, $revision, $fragmentId, $render);
		$service->invalidateTags(['widget.query_results']);
		$service->remember($node, $context, $revision, $fragmentId, $render);
		$service->invalidateCmsDependencies();
		$service->remember($node, $context, $revision, $fragmentId, $render);

		self::assertSame(3, $renderCount);
	}

	public function testCacheBackendFailureFallsBackToRendering(): void
	{
		$cache = $this->createStub(TagAwareCacheInterface::class);
		$cache->method('get')->willThrowException(new \RuntimeException('cache unavailable'));
		$service = new RenderedFragmentCache(
			$cache,
			$this->createStub(EntityManagerInterface::class),
			$this->createStub(Security::class),
		);
		$renderCount = 0;
		$result = $service->remember(
			['cacheMode' => 'global'],
			$this->context(11),
			str_repeat('3', 64),
			str_repeat('4', 64),
			static function () use (&$renderCount): array {
				++$renderCount;

				return ['html' => '<p>uncached</p>', 'styles' => []];
			},
		);

		self::assertSame(1, $renderCount);
		self::assertSame('<p>uncached</p>', $result['html']);
	}

	private function cacheService(?Security $security, ?EntityManagerInterface $entityManager = null): RenderedFragmentCache
	{
		$security ??= $this->createStub(Security::class);
		$entityManager ??= $this->createStub(EntityManagerInterface::class);
		$cache = new TagAwareAdapter(new ArrayAdapter());

		return new RenderedFragmentCache($cache, $entityManager, $security);
	}

	private function context(int $siteId, bool $editorMode = false, bool $canEdit = false): BlockRenderContext
	{
		$site = new Site();
		$this->setId($site, $siteId);

		return new BlockRenderContext(
			editorMode: $editorMode,
			canEdit: $canEdit,
			site: $site,
			request: Request::create('https://example.test/page'),
		);
	}

	/** @param list<int|string> $groupIds */
	private function userWithGroups(array $groupIds): User
	{
		$user = new User();
		$properties = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$properties->setValue($user, new ArrayCollection());
		$user->setProperty('groups', $groupIds);

		return $user;
	}

	private function setId(DbObject $object, int $id): void
	{
		(new \ReflectionClass(DbObject::class))->getProperty('id')->setValue($object, $id);
	}
}
