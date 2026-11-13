<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\PluginActionMatcher;
use iikiti\CMS\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginActionMatcherTest extends TestCase
{
	public function testSameClaimsAreAdvisoryUnlessDeclaredAsConflicts(): void
	{
		$matcher = new PluginActionMatcher();
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', ['content.transform.minify.html']);

		self::assertSame([], $matcher->findConflicts($candidate, [$other]));
	}

	public function testFindsOneSidedConflictDeclarations(): void
	{
		$matcher = new PluginActionMatcher();
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html'], ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', ['content.transform.minify.html']);

		$conflicts = $matcher->findConflicts($candidate, [$other]);

		self::assertCount(1, $conflicts);
		self::assertSame(['plugin' => 'optimizer-b', 'action' => 'content.transform.minify.html'], $conflicts[0]->toArray());
	}

	public function testFindsConflictDeclaredByTheActivePlugin(): void
	{
		$matcher = new PluginActionMatcher();
		$candidate = $this->manifest('optimizer-a', ['content.transform.minify.html']);
		$other = $this->manifest('optimizer-b', [], ['content.transform.minify.html']);

		self::assertSame('optimizer-b', $matcher->findConflicts($candidate, [$other])[0]->pluginSlug);
	}

	public function testFindsClaimantsByExactActionId(): void
	{
		$matcher = new PluginActionMatcher();
		$plugins = [
			$this->manifest('security-a', ['security.firewall.inspect.sqli']),
			$this->manifest('security-b', ['security.firewall.inspect.xss']),
		];

		self::assertSame(['security-a'], array_map(
			static fn (PluginManifest $plugin): string => $plugin->slug,
			$matcher->findClaimants($plugins, 'security.firewall.inspect.sqli'),
		));
	}

	/**
	 * @param list<string> $actions
	 * @param list<string> $conflicts
	 */
	private function manifest(string $slug, array $actions = [], array $conflicts = []): PluginManifest
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
