<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\FirewallCompiledRuleSet;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRuleSetCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class FirewallRuleSetCacheTest extends TestCase
{
	public function testReusesSiteRulesetsAndInvalidatesOnlyTheUpdatedSite(): void
	{
		$cache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$compileCount = 0;
		$compile = static function () use (&$compileCount): FirewallCompiledRuleSet {
			++$compileCount;

			return new FirewallCompiledRuleSet([], new FirewallConditionRegistry());
		};

		$cache->get(1, true, $compile);
		$cache->get(1, true, $compile);
		$cache->get(2, true, $compile);
		self::assertSame(2, $compileCount);

		$cache->invalidateSite(1);
		$cache->get(1, true, $compile);
		$cache->get(2, true, $compile);
		self::assertSame(3, $compileCount);

		$cache->invalidateAll();
		$cache->get(1, true, $compile);
		$cache->get(2, true, $compile);
		self::assertSame(5, $compileCount);
	}

	public function testKeepsGlobalAndSiteRulesetsSeparatelyCached(): void
	{
		$cache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$compileCount = 0;
		$compile = static function () use (&$compileCount): FirewallCompiledRuleSet {
			++$compileCount;

			return new FirewallCompiledRuleSet([], new FirewallConditionRegistry());
		};

		$cache->get(1, true, $compile);
		$cache->get(1, false, $compile);
		self::assertSame(2, $compileCount);

		$cache->invalidateSite(1);
		$cache->get(1, true, $compile);
		$cache->get(1, false, $compile);
		self::assertSame(4, $compileCount);
	}

	public function testFilesystemCacheReusesSerializedCompiledRulesetsAcrossInstances(): void
	{
		$directory = sys_get_temp_dir().'/iikiti-firewall-cache-'.bin2hex(random_bytes(6));
		$compileCount = 0;
		$compile = static function () use (&$compileCount): FirewallCompiledRuleSet {
			++$compileCount;

			return new FirewallCompiledRuleSet([], new FirewallConditionRegistry());
		};
		try {
			(new FirewallRuleSetCache(new FilesystemAdapter('firewall-test', 0, $directory), new NullLogger()))->get(5, true, $compile);
			(new FirewallRuleSetCache(new FilesystemAdapter('firewall-test', 0, $directory), new NullLogger()))->get(5, true, $compile);

			self::assertSame(1, $compileCount);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	private function removeDirectory(string $directory): void
	{
		$entries = @scandir($directory);
		if (false === $entries) {
			return;
		}
		foreach ($entries as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$path = $directory.'/'.$entry;
			if (is_dir($path) && !is_link($path)) {
				$this->removeDirectory($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($directory);
	}
}
