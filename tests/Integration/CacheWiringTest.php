<?php

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Cache\CachingStrategyRegistry;
use iikiti\CMS\Cache\Strategy\DoctrineResultCacheStrategy;
use iikiti\CMS\Event\Subscriber\BatchCacheDisablerSubscriber;
use iikiti\CMS\Service\CacheState;
use iikiti\CMS\Service\DatabaseCacheManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the database cache services are wired into the kernel.
 */
final class CacheWiringTest extends KernelTestCase
{
	public function testCacheServicesAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$this->assertInstanceOf(CacheState::class, $container->get(CacheState::class));
		$this->assertInstanceOf(DatabaseCacheManager::class, $container->get(DatabaseCacheManager::class));
		$this->assertInstanceOf(
			CachingStrategyRegistry::class,
			$container->get(CachingStrategyRegistry::class)
		);
		$this->assertInstanceOf(
			BatchCacheDisablerSubscriber::class,
			$container->get(BatchCacheDisablerSubscriber::class)
		);
	}

	public function testDefaultStrategiesAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		/** @var CachingStrategyRegistry $registry */
		$registry = $container->get(CachingStrategyRegistry::class);

		$this->assertTrue($registry->hasStrategy(DoctrineResultCacheStrategy::NAME));
		$this->assertTrue($registry->hasStrategy('none'));

		$names = array_map(
			static fn (object $metadata): string => $metadata->name,
			$registry->getAvailableStrategies()
		);
		$this->assertContains(DoctrineResultCacheStrategy::NAME, $names);
		$this->assertContains('none', $names);
	}

	public function testStrategiesExposeMetadataForEnumeration(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		/** @var CachingStrategyRegistry $registry */
		$registry = $container->get(CachingStrategyRegistry::class);
		$metadata = $registry->getStrategy(DoctrineResultCacheStrategy::NAME);

		$this->assertNotNull($metadata);
		$this->assertSame('Doctrine ORM Result Cache', $metadata->getLabel());
		$this->assertNotSame('', (string) $metadata->getDescription());
		$this->assertContains('query_level', $metadata->getCapabilities());
	}
}
