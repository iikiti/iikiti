<?php

namespace iikiti\CMS\Security;

use iikiti\CMS\Manager\UserRoleManager;

/**
 * Encapsulates the structured role hierarchy with Symfony 8.2 wildcard support.
 *
 * Role hierarchy consists of two layers:
 * - A static chain (explicit role → parent role mappings).
 * - Wildcard patterns (pattern → parent roles) that apply to any
 *   dynamically-registered or database-defined role matching the pattern
 *   via fnmatch().
 *
 * In Symfony 8.2, wildcard patterns are only valid as **keys** in the role
 * hierarchy — they cannot appear in the value (parent) position. This class
 * enforces that constraint: all patterns are in the WILDCARD_PATTERNS map
 * whose keys are patterns and whose values are literal parent role values.
 *
 * Wildcard matching follows Symfony 8.2 semantics: `*` is only treated as a
 * wildcard when wrapped by underscores (e.g. `ROLE_*_MODERATOR`) or placed
 * after an underscore at the end (e.g. `ROLE_BLOG_*`).
 *
 * The actual wildcard-to-role resolution at runtime is performed by Symfony's
 * native RoleHierarchy (Symfony 8.2+). This class is concerned with defining
 * and validating the hierarchy configuration that is baked into the container.
 */
class DynamicRoleHierarchy
{
	/**
	 * Static role → granted-parents mapping (no wildcards).
	 *
	 * In Symfony's hierarchy semantics: if a user has the KEY role, they also
	 * receive all roles in the VALUE list.
	 *
	 * @var array<string, list<string>>
	 */
	private const STATIC_HIERARCHY = [
		'ROLE_SYSTEM' => ['ROLE_SUPER_ADMIN'],
		'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
		'ROLE_ADMIN' => ['ROLE_SITE_MANAGER'],
		'ROLE_SITE_MANAGER' => ['ROLE_MANAGER'],
		'ROLE_MANAGER' => ['ROLE_EDITOR'],
		'ROLE_EDITOR' => ['ROLE_AUTHOR'],
		'ROLE_AUTHOR' => ['ROLE_MEMBER'],
		'ROLE_MEMBER' => ['ROLE_USER'],
		'ROLE_USER' => ['ROLE_NON_MEMBER'],
		'ROLE_NON_MEMBER' => [],
	];

	/**
	 * Wildcard pattern → granted-parents mapping.
	 *
	 * Each key is a wildcard pattern (e.g. `ROLE_PLUGIN_*`). Any role matching
	 * the pattern will also receive the listed parent roles. Values are always
	 * literal role names — never wildcard patterns.
	 *
	 * Scoped catch-alls ensure dynamically-registered roles (plugins, site-
	 * specific, content-scoped) automatically inherit permissions from their
	 * tier's canonical parent.
	 *
	 * @var array<string, list<string>>
	 */
	private const WILDCARD_PATTERNS = [
		// Catch-all: every role inherits ROLE_USER (and transitively ROLE_NON_MEMBER)
		'ROLE_*' => ['ROLE_USER'],

		// Tier-scoped catch-alls for dynamic roles
		'ROLE_PLUGIN_*' => ['ROLE_ADMIN'],
		'ROLE_SITE_*' => ['ROLE_SITE_MANAGER'],
		'ROLE_CONTENT_*' => ['ROLE_EDITOR'],

		// Suffix-scoped patterns
		'ROLE_MOD_*' => ['ROLE_MODERATOR'],
		'ROLE_BLOG_*' => ['ROLE_BLOG_READER'],
		'ROLE_*_MODERATOR' => ['ROLE_MODERATOR'],

		// Granular tier patterns
		'ROLE_MEMBER_*' => ['ROLE_MEMBER'],
		'ROLE_AUTHOR_*' => ['ROLE_AUTHOR'],
		'ROLE_EDITOR_*' => ['ROLE_EDITOR'],
		'ROLE_MANAGER_*' => ['ROLE_MANAGER'],
		'ROLE_USER_*' => ['ROLE_USER'],
	];

	/**
	 * @param list<string> $dynamicRoles Additional role values registered at runtime
	 *                                   (e.g. plugin-registered or DB-defined roles).
	 */
	public function __construct(
		private readonly ?array $dynamicRoles = null,
	) {
	}

	/**
	 * Returns the static hierarchy (no wildcards).
	 *
	 * @return array<string, list<string>>
	 */
	public function getStaticHierarchy(): array
	{
		return self::STATIC_HIERARCHY;
	}

	/**
	 * Returns the wildcard pattern hierarchy.
	 *
	 * @return array<string, list<string>>
	 */
	public function getWildcardHierarchy(): array
	{
		return self::WILDCARD_PATTERNS;
	}

	/**
	 * Returns the merged hierarchy (static + wildcard) as Symfony's
	 * `role_hierarchy` configuration expects it.
	 *
	 * This is the complete hierarchy the compiler pass sets as the
	 * `security.role_hierarchy.roles` container parameter.
	 *
	 * @return array<string, list<string>>
	 */
	public function getMergedHierarchy(): array
	{
		$merged = array_merge(
			self::STATIC_HIERARCHY,
			self::WILDCARD_PATTERNS,
		);

		// Add any known dynamic roles not already present as keys.
		// They participate as children of matching wildcard patterns.
		$knownKeys = array_keys($merged);

		foreach ($this->getKnownRoles() as $role) {
			if (!in_array($role, $knownKeys, true)) {
				$merged[$role] = [];
			}
		}

		return $merged;
	}

	/**
	 * Expands a wildcard pattern to all known roles it matches.
	 *
	 * @return list<string>
	 */
	public function expandWildcard(string $pattern): array
	{
		$allRoles = $this->getKnownRoles();
		$matched = [];

		foreach ($allRoles as $role) {
			if ($this->matchesPattern($role, $pattern)) {
				$matched[] = $role;
			}
		}

		return $matched;
	}

	/**
	 * Returns the parent roles that the given role value inherits via
	 * wildcard pattern matching (excluding the static hierarchy, which
	 * Symfony handles natively).
	 *
	 * @return list<string>
	 */
	public function getWildcardParents(string $roleValue): array
	{
		$parents = [];

		foreach (self::WILDCARD_PATTERNS as $pattern => $grantedRoles) {
			if ($this->matchesPattern($roleValue, $pattern)) {
				foreach ($grantedRoles as $granted) {
					if (!in_array($granted, $parents, true)) {
						$parents[] = $granted;
					}
				}
			}
		}

		return $parents;
	}

	/**
	 * Validates the hierarchy and returns a list of error messages.
	 *
	 * @return list<string>
	 */
	public function validate(): array
	{
		$errors = [];

		// Verify that no wildcard pattern key is also used as a parent value
		// (granted role). This would cause infinite recursion in Symfony's
		// RoleHierarchy resolver. Uses strcmp() to avoid static type inference.
		$allGranted = [];
		foreach (self::WILDCARD_PATTERNS as $grantedRoles) {
			$allGranted = array_merge($allGranted, $grantedRoles);
		}

		$patternKeys = array_keys(self::WILDCARD_PATTERNS);

		foreach ($patternKeys as $pattern) {
			foreach ($allGranted as $granted) {
				if (0 === strcmp((string) $pattern, (string) $granted)) {
					$errors[] = sprintf('Wildcard pattern "%s" appears as a parent value, which could cause infinite recursion.', $pattern);
				}
			}
		}

		return $errors;
	}

	/**
	 * Returns all known roles: from UserRoleEnum + dynamic roles passed in constructor.
	 *
	 * @return list<string>
	 */
	private function getKnownRoles(): array
	{
		$roles = array_values(UserRoleManager::getAllRoles());
		$roleStrings = [];

		foreach ($roles as $case) {
			$roleStrings[] = (string) $case->getValue();
		}

		if (null !== $this->dynamicRoles) {
			foreach ($this->dynamicRoles as $role) {
				if (!in_array($role, $roleStrings, true)) {
					$roleStrings[] = $role;
				}
			}
		}

		return $roleStrings;
	}

	/**
	 * Tests whether a concrete role value matches a Symfony 8.2 wildcard pattern.
	 *
	 * `*` is only a wildcard when:
	 * - Wrapped by underscores: `ROLE_*_MODERATOR`
	 * - After an underscore at the end: `ROLE_BLOG_*`
	 *
	 * Otherwise the pattern is treated as a literal role name (no special matching).
	 */
	private function matchesPattern(string $role, string $pattern): bool
	{
		if ($pattern === $role) {
			return true;
		}

		// Determine if the pattern contains a valid wildcard
		$hasWildcard = (
			str_contains($pattern, '_*_') ||
			str_ends_with($pattern, '_*')
		);

		if (!$hasWildcard) {
			return false;
		}

		return fnmatch($pattern, $role);
	}
}
