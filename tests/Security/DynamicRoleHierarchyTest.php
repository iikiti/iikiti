<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Enum\UserRoleEnum;
use iikiti\CMS\Security\DynamicRoleHierarchy;
use PHPUnit\Framework\TestCase;

final class DynamicRoleHierarchyTest extends TestCase
{
	public function testGetStaticHierarchyReturnsCoreRoles(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$static = $hierarchy->getStaticHierarchy();

		self::assertArrayHasKey('ROLE_SYSTEM', $static);
		self::assertSame(['ROLE_SUPER_ADMIN'], $static['ROLE_SYSTEM']);
		self::assertArrayHasKey('ROLE_ADMIN', $static);
		self::assertSame(['ROLE_SITE_MANAGER'], $static['ROLE_ADMIN']);
		self::assertArrayHasKey('ROLE_NON_MEMBER', $static);
		self::assertSame([], $static['ROLE_NON_MEMBER']);
	}

	public function testGetWildcardHierarchyContainsScopedPatterns(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$wildcard = $hierarchy->getWildcardHierarchy();

		self::assertArrayHasKey('ROLE_*', $wildcard);
		self::assertSame(['ROLE_USER'], $wildcard['ROLE_*']);
		self::assertArrayHasKey('ROLE_PLUGIN_*', $wildcard);
		self::assertSame(['ROLE_ADMIN'], $wildcard['ROLE_PLUGIN_*']);
		self::assertArrayHasKey('ROLE_SITE_*', $wildcard);
		self::assertSame(['ROLE_SITE_MANAGER'], $wildcard['ROLE_SITE_*']);
		self::assertArrayHasKey('ROLE_*_MODERATOR', $wildcard);
		self::assertSame(['ROLE_MODERATOR'], $wildcard['ROLE_*_MODERATOR']);
	}

	public function testGetMergedHierarchyIncludesBothLayers(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$merged = $hierarchy->getMergedHierarchy();

		// Static hierarchy entries present
		self::assertSame(['ROLE_SUPER_ADMIN'], $merged['ROLE_SYSTEM']);
		self::assertSame([], $merged['ROLE_NON_MEMBER']);

		// Wildcard patterns present
		self::assertSame(['ROLE_USER'], $merged['ROLE_*']);
		self::assertSame(['ROLE_ADMIN'], $merged['ROLE_PLUGIN_*']);
		self::assertSame(['ROLE_MODERATOR'], $merged['ROLE_*_MODERATOR']);

		// Dynamically-registered enum roles present as standalone keys
		$allRoles = UserRoleEnum::cases();
		foreach ($allRoles as $case) {
			$value = (string) $case->getValue();
			self::assertArrayHasKey($value, $merged, sprintf('Role "%s" should be in merged hierarchy', $value));
		}
	}

	public function testMergedHierarchyKeysAreAllLiteralOrValidWildcards(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$merged = $hierarchy->getMergedHierarchy();

		foreach ($merged as $key => $parents) {
			// All parent values must be literal role names (no wildcard patterns in values)
			foreach ($parents as $parent) {
				self::assertStringNotContainsString('*', $parent, sprintf(
					'Wildcard pattern "%s" should not appear as a parent value',
					$parent,
				));
			}
		}
	}

	public function testExpandWildcardReturnsMatchingRoles(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$expanded = $hierarchy->expandWildcard('ROLE_*');

		self::assertContains('ROLE_USER', $expanded);
		self::assertContains('ROLE_ADMIN', $expanded);
		self::assertContains('ROLE_NON_MEMBER', $expanded);
	}

	public function testExpandWildcardMatchesSiteScopedRoles(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_SITE_BLOGGER']);

		$expanded = $hierarchy->expandWildcard('ROLE_SITE_*');

		self::assertContains('ROLE_SITE_BLOGGER', $expanded);
		self::assertNotContains('ROLE_USER', $expanded);
	}

	public function testExpandWildcardMatchesPluginScopedRoles(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_PLUGIN_SEOPACK']);

		$expanded = $hierarchy->expandWildcard('ROLE_PLUGIN_*');

		self::assertContains('ROLE_PLUGIN_SEOPACK', $expanded);
	}

	public function testExpandWildcardMatchesSuffixPattern(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_FORUM_MODERATOR']);

		$expanded = $hierarchy->expandWildcard('ROLE_*_MODERATOR');

		self::assertContains('ROLE_FORUM_MODERATOR', $expanded);
	}

	public function testExpandWildcardDoesNotMatchNonWildcard(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_EDITOR']);

		$expanded = $hierarchy->expandWildcard('ROLE_SITE_*');

		self::assertNotContains('ROLE_EDITOR', $expanded);
	}

	public function testGetWildcardParentsReturnsMatchingPatterns(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_SITE_BLOGGER']);

		$parents = $hierarchy->getWildcardParents('ROLE_SITE_BLOGGER');

		self::assertContains('ROLE_SITE_MANAGER', $parents);
		self::assertContains('ROLE_USER', $parents); // Matches ROLE_* too
	}

	public function testGetWildcardParentsForPluginRole(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_PLUGIN_SEOPACK']);

		$parents = $hierarchy->getWildcardParents('ROLE_PLUGIN_SEOPACK');

		self::assertContains('ROLE_ADMIN', $parents);
		self::assertContains('ROLE_USER', $parents);
	}

	public function testGetWildcardParentsForModeratorRole(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_FORUM_MODERATOR']);

		$parents = $hierarchy->getWildcardParents('ROLE_FORUM_MODERATOR');

		self::assertContains('ROLE_MODERATOR', $parents);
		self::assertContains('ROLE_USER', $parents);
	}

	public function testGetWildcardParentsForKnownSystemRole(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		// ROLE_ADMIN matches ROLE_* → ROLE_USER
		$parents = $hierarchy->getWildcardParents('ROLE_ADMIN');

		self::assertContains('ROLE_USER', $parents);
	}

	public function testValidateReturnsNoErrorsForValidHierarchy(): void
	{
		$hierarchy = new DynamicRoleHierarchy();

		$errors = $hierarchy->validate();

		self::assertSame([], $errors);
	}

	public function testMatchesPatternTreatsNonWildcardPatternAsLiteral(): void
	{
		$hierarchy = new DynamicRoleHierarchy(['ROLE_ADMIN']);

		$expanded = $hierarchy->expandWildcard('ROLE_ADMIN');

		// ROLE_ADMIN is a literal match, not a wildcard — it matches itself
		self::assertContains('ROLE_ADMIN', $expanded);
	}

	public function testMatchesPatternDoesNotMatchWithoutUnderscoreBeforeWildcard(): void
	{
		// Per Symfony 8.2 rules: ROLE_BLOG* (no underscore before *) is a literal, not wildcard
		$hierarchy = new DynamicRoleHierarchy(['ROLE_BLOGREADER']);

		$expanded = $hierarchy->expandWildcard('ROLE_BLOG*');

		// Should be empty — ROLE_BLOG* is a literal role name, not a wildcard pattern
		self::assertSame([], $expanded);

		// But ROLE_BLOG_* (with underscore) IS a wildcard
		$expanded2 = $hierarchy->expandWildcard('ROLE_BLOG_*');

		self::assertNotContains('ROLE_BLOGREADER', $expanded2);
	}
}
