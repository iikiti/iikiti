<?php

namespace iikiti\CMS\DependencyInjection\Compiler;

use iikiti\CMS\Enum\UserRoleEnum;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Security\DynamicRoleHierarchy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Merges the dynamic role hierarchy (static + wildcard patterns + enum-registered
 * roles) into the Symfony `security.role_hierarchy.roles` parameter at container
 * compile time.
 *
 * The hierarchy is built from two sources:
 * 1. Core + plugin-registered roles from {@see UserRoleEnum} (registered at
 *    bundle build time, available during compilation).
 * 2. Wildcard pattern definitions from {@see DynamicRoleHierarchy}.
 *
 * Database-defined `Role` entities are NOT fetched at compile time (the EntityManager
 * is unavailable during container build). Instead, Symfony 8.2's native wildcard
 * resolution matches DB roles at runtime against the pattern keys in the hierarchy.
 * For example, a `ROLE_PLUGIN_SEOPACK` Role entity in the DB will match the
 * `ROLE_PLUGIN_*` pattern and inherit `ROLE_ADMIN` without needing to be
 * explicitly listed.
 *
 * Database role values that don't match any wildcard pattern are still
 * introspectable via {@see UserRoleEnum} if registered by plugins/migrations.
 * The {@see DynamicRoleHierarchyPass} ensures all enum-registered roles appear
 * as standalone keys in the hierarchy so `debug:roles` lists them.
 */
class DynamicRoleHierarchyPass implements CompilerPassInterface
{
	public function process(ContainerBuilder $container): void
	{
		if (!$container->hasParameter('security.role_hierarchy.roles')) {
			$container->setParameter('security.role_hierarchy.roles', []);

			return;
		}

		// Collect role values from UserRoleEnum (core + plugin-registered at
		// bundle build time, available during container compilation).
		$allRoles = UserRoleManager::getAllRoles();
		$knownRoles = array_map(static fn ($case): string => (string) $case->getValue(), $allRoles);

		// Build the merged hierarchy — static chain + wildcard patterns +
		// standalone keys for any known roles not already covered by a pattern.
		$hierarchy = new DynamicRoleHierarchy($knownRoles);

		$merged = $hierarchy->getMergedHierarchy();

		$container->setParameter('security.role_hierarchy.roles', $merged);
	}
}
