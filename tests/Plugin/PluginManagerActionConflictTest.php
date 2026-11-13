<?php

namespace iikiti\CMS\Tests\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\Exception\PluginActionConflictException;
use iikiti\CMS\Plugin\Lifecycle\PluginLifecycleHandler;
use iikiti\CMS\Plugin\PluginActionMatcher;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Plugin\PluginValidator;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\QueryBuilderFactory;
use iikiti\CMS\Service\Configuration;
use PHPUnit\Framework\TestCase;

final class PluginManagerActionConflictTest extends TestCase
{
	public function testActivationPreflightRejectsConflictsAndReportsSite(): void
	{
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', [], ['content.transform.minify.html']);
		$site = $this->site('4', ['optimizer-b']);
		$manager = $this->manager([$candidate, $other], [$site]);

		try {
			$manager->assertCanActivateForSites($candidate, ['4']);
			self::fail('Expected the hard conflict to prevent activation.');
		} catch (PluginActionConflictException $exception) {
			self::assertSame([
				['site' => '4', 'plugin' => 'optimizer-b', 'action' => 'content.transform.minify.html'],
			], $exception->getConflicts());
		}
	}

	public function testExplicitOverrideReturnsConflictsWithoutBlocking(): void
	{
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', [], ['content.transform.minify.html']);
		$manager = $this->manager([$candidate, $other], [$this->site('4', ['optimizer-b'])]);

		self::assertSame([
			['site' => '4', 'plugin' => 'optimizer-b', 'action' => 'content.transform.minify.html'],
		], $manager->assertCanActivateForSites($candidate, ['4'], true));
	}

	public function testMultiSiteActivationPreflightsBeforeAnyMutation(): void
	{
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html'], ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', ['content.transform.minify.html']);
		$siteOne = $this->createMock(Site::class);
		$siteOne->method('getId')->willReturn('1');
		$siteOne->expects(self::never())->method('setConfiguration');
		$configOne = $this->createMock(Configuration::class);
		$configOne->method('getActivePlugins')->willReturn([]);
		$configOne->method('isPluginActive')->willReturn(false);
		$configOne->expects(self::never())->method('enablePlugin');
		$siteOne->method('getConfiguration')->willReturn($configOne);
		$siteTwo = $this->site('2', ['optimizer-b']);
		$manager = $this->manager([$candidate, $other], [$siteOne, $siteTwo]);

		$this->expectException(PluginActionConflictException::class);
		$manager->activateForSites('optimizer-a', ['1', '2']);
	}

	public function testInstalledButInactivePluginDoesNotConflict(): void
	{
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', [], ['content.transform.minify.html']);
		$manager = $this->manager([$candidate, $other], [$this->site('4', [])]);

		self::assertSame([], $manager->assertCanActivateForSites($candidate, ['4']));
	}

	public function testUpdatePreflightChecksAnAlreadyActivePlugin(): void
	{
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', [], ['content.transform.minify.html']);
		$manager = $this->manager([$candidate, $other], [$this->site('4', ['optimizer-a', 'optimizer-b'])]);

		$this->expectException(PluginActionConflictException::class);
		$manager->assertCanActivateForSites($candidate, ['4'], false, false);
	}

	/**
	 * @param list<PluginManifest> $manifests
	 * @param list<Site>           $sites
	 */
	private function manager(array $manifests, array $sites): PluginManager
	{
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('find')->willReturnCallback(static function (string $class, mixed $id) use ($sites): ?Site {
			foreach ($sites as $site) {
				if ((string) $site->getId() === (string) $id) {
					return $site;
				}
			}

			return null;
		});
		$connection = $this->createStub(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$factory = new QueryBuilderFactory(
			$connection,
			new DatabasePlatformRegistry([new PostgreSQLPlatformStrategy()], PostgreSQLPlatformStrategy::NAME),
			true,
		);
		$manager = new PluginManager(
			$this->createStub(PluginLoader::class),
			$this->createStub(PluginValidator::class),
			$this->createStub(PluginRegistry::class),
			new PluginActionMatcher(),
			$this->createStub(PluginLifecycleHandler::class),
			$this->createStub(PluginContainerRebuilder::class),
			$factory,
			$entityManager,
			'test',
		);
		$installed = [];
		foreach ($manifests as $manifest) {
			$installed[$manifest->slug] = ['manifest' => $manifest, 'path' => '', 'installInfo' => null];
		}
		(new \ReflectionProperty($manager, 'installedCache'))->setValue($manager, $installed);

		return $manager;
	}

	/** @param list<string> $activePlugins */
	private function site(string $id, array $activePlugins): Site
	{
		$site = $this->createStub(Site::class);
		$site->method('getId')->willReturn($id);
		$configuration = $this->createStub(Configuration::class);
		$configuration->method('getActivePlugins')->willReturn($activePlugins);
		$site->method('getConfiguration')->willReturn($configuration);

		return $site;
	}

	/**
	 * @param list<string> $actions
	 * @param list<string> $conflicts
	 */
	private function manifest(string $slug, array $actions, array $conflicts = []): PluginManifest
	{
		return new PluginManifest(
			name: $slug,
			slug: $slug,
			namespace: 'Acme\\Plugin\\Test',
			bundleClass: 'Acme\\Plugin\\Test\\TestBundle',
			version: '1.0.0',
			iikitiVersion: '^1.0',
			actions: $actions,
			actionConflicts: $conflicts,
		);
	}
}
