<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\DependencyInjection\Compiler;

use iikiti\CMS\DependencyInjection\Compiler\DynamicRoleHierarchyPass;
use iikiti\CMS\Enum\UserRoleEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DynamicRoleHierarchyPassTest extends TestCase
{
	public function testProcessSetsMergedHierarchyParameter(): void
	{
		$container = new ContainerBuilder();
		$container->setParameter('security.role_hierarchy.roles', ['ROLE_USER' => []]);

		$pass = new DynamicRoleHierarchyPass();
		$pass->process($container);

		$merged = $container->getParameter('security.role_hierarchy.roles');

		self::assertIsArray($merged);
		// Static hierarchy
		self::assertArrayHasKey('ROLE_SYSTEM', $merged);
		self::assertSame(['ROLE_SUPER_ADMIN'], $merged['ROLE_SYSTEM']);
		// Wildcard patterns
		self::assertArrayHasKey('ROLE_*', $merged);
		self::assertSame(['ROLE_USER'], $merged['ROLE_*']);
		self::assertArrayHasKey('ROLE_PLUGIN_*', $merged);
		self::assertSame(['ROLE_ADMIN'], $merged['ROLE_PLUGIN_*']);
		self::assertArrayHasKey('ROLE_SITE_*', $merged);
		self::assertSame(['ROLE_SITE_MANAGER'], $merged['ROLE_SITE_*']);
	}

	public function testProcessIncludesAllEnumRegisteredRoles(): void
	{
		$container = new ContainerBuilder();
		$container->setParameter('security.role_hierarchy.roles', []);

		$pass = new DynamicRoleHierarchyPass();
		$pass->process($container);

		$merged = $container->getParameter('security.role_hierarchy.roles');

		$allRoles = UserRoleEnum::cases();
		foreach ($allRoles as $case) {
			$value = (string) $case->getValue();
			self::assertArrayHasKey($value, $merged, sprintf('Role "%s" should be in merged hierarchy', $value));
		}
	}

	public function testProcessSetsEmptyArrayWhenParameterMissing(): void
	{
		$container = new ContainerBuilder();

		$pass = new DynamicRoleHierarchyPass();
		$pass->process($container);

		self::assertSame([], $container->getParameter('security.role_hierarchy.roles'));
	}

	public function testProcessDoesNotMutateStaticHierarchyValues(): void
	{
		$container = new ContainerBuilder();
		$container->setParameter('security.role_hierarchy.roles', [
			'ROLE_USER' => ['ROLE_NON_MEMBER'],
		]);

		$pass = new DynamicRoleHierarchyPass();
		$pass->process($container);

		$merged = $container->getParameter('security.role_hierarchy.roles');

		// The static chain is preserved from DynamicRoleHierarchy, not the
		// arbitrary input parameter.
		self::assertSame(['ROLE_SUPER_ADMIN'], $merged['ROLE_SYSTEM']);
		self::assertArrayHasKey('ROLE_NON_MEMBER', $merged);
	}
}
