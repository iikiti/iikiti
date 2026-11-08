<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Event\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Event\Subscriber\CacheInvalidationSubscriber;
use iikiti\CMS\Service\DatabaseCacheManager;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;

final class CacheInvalidationSubscriberTest extends TestCase
{
	public function testCmsObjectAndPropertyWritesInvalidateRenderedFragmentsAndQueryGenerations(): void
	{
		$unitOfWork = $this->createStub(UnitOfWork::class);
		$unitOfWork->method('getScheduledEntityInsertions')->willReturn([new Page()]);
		$unitOfWork->method('getScheduledEntityUpdates')->willReturn([new ObjectProperty()]);
		$unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

		$cacheManager = $this->createMock(DatabaseCacheManager::class);
		$cacheManager->expects(self::exactly(3))->method('invalidate')->willReturnCallback(static function (string $class): void {
			self::assertContains($class, [Page::class, DbObject::class, ObjectProperty::class]);
		});
		$renderedCache = new RenderedFragmentCache(
			new TagAwareAdapter(new ArrayAdapter()),
			$this->createStub(EntityManagerInterface::class),
			$this->createStub(Security::class),
		);
		$site = new Site();
		(new \ReflectionClass(DbObject::class))->getProperty('id')->setValue($site, 11);
		$context = new BlockRenderContext(false, site: $site, request: Request::create('/page'));
		$renderCount = 0;
		$render = static function () use (&$renderCount): array {
			++$renderCount;

			return ['html' => '<p>cached</p>', 'styles' => []];
		};
		$node = ['cacheMode' => 'global'];
		$revision = str_repeat('a', 64);
		$fragmentId = str_repeat('b', 64);
		$renderedCache->remember($node, $context, $revision, $fragmentId, $render);
		$subscriber = new CacheInvalidationSubscriber($cacheManager, $renderedCache);

		$subscriber->onFlush(new OnFlushEventArgs($entityManager));
		$subscriber->postFlush(new PostFlushEventArgs($entityManager));
		$renderedCache->remember($node, $context, $revision, $fragmentId, $render);

		self::assertSame(2, $renderCount);
	}
}
