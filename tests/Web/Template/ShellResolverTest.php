<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Web\Template\Rule\SiteRule;
use iikiti\CMS\Web\Template\ShellResolver;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use PHPUnit\Framework\TestCase;

final class ShellResolverTest extends TestCase
{
	private function context(int $siteId = 1): TemplateResolutionContext
	{
		$site = $this->createStub(Site::class);
		$site->method('getId')->willReturn($siteId);

		return new TemplateResolutionContext(site: $site);
	}

	public function testReturnsEmptyListForRoleWithNoShells(): void
	{
		$resolver = new ShellResolver([new SiteRule()]);

		$resolved = $resolver->resolve([], $this->context());

		$this->assertSame([], $resolved);
	}

	public function testRendersAllMatchingShellsInAscendingPriorityOrder(): void
	{
		$resolver = new ShellResolver([new SiteRule()]);
		$shells = [
			['id' => 'high', 'role' => 'header', 'priority' => 10, 'display_rules' => [['rule' => 'site', 'config' => ['site_id' => 1]]]],
			['id' => 'low', 'role' => 'header', 'priority' => 1, 'display_rules' => [['rule' => 'site', 'config' => ['site_id' => 1]]]],
		];

		$resolved = $resolver->resolve($shells, $this->context());

		$this->assertSame(['low', 'high'], array_column($resolved, 'id'));
	}

	public function testSkipsShellWhenNoRuleMatches(): void
	{
		$resolver = new ShellResolver([new SiteRule()]);
		$shells = [
			['id' => 'other-site', 'role' => 'footer', 'priority' => 0, 'display_rules' => [['rule' => 'site', 'config' => ['site_id' => 2]]]],
		];

		$this->assertSame([], $resolver->resolve($shells, $this->context(1)));
	}

	public function testSkipsDisabledShells(): void
	{
		$resolver = new ShellResolver([new SiteRule()]);
		$shells = [
			['id' => 'off', 'role' => 'footer', 'enabled' => false, 'priority' => 0, 'display_rules' => [['rule' => 'site', 'config' => ['site_id' => 1]]]],
		];

		$this->assertSame([], $resolver->resolve($shells, $this->context()));
	}

	public function testUnknownRuleDoesNotMatch(): void
	{
		$resolver = new ShellResolver([new SiteRule()]);
		$shells = [
			['id' => 'plugin', 'role' => 'footer', 'priority' => 0, 'display_rules' => [['rule' => 'not_registered', 'config' => []]]],
		];

		$this->assertSame([], $resolver->resolve($shells, $this->context()));
	}
}
