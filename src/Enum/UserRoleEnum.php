<?php

namespace iikiti\CMS\Enum;

/**
 * Handles an arbitrary number of user roles.
 */
final class UserRoleEnum extends DynamicBackedEnumerator
{
	protected static array $defaultRoles = [];

	public static function registerDefault(string|int $role): void
	{
		$role = self::from($role);
		self::$defaultRoles = array_merge([$role->getName() => $role], self::$defaultRoles);
	}

	public static function getDefaultRoles(): array
	{
		return self::$defaultRoles;
	}
}

UserRoleEnum::register('User', 'ROLE_USER');
UserRoleEnum::registerDefault('ROLE_USER');
UserRoleEnum::register('Administrator', 'ROLE_ADMIN');
UserRoleEnum::register('Super Administrator', 'ROLE_SUPER_ADMIN');
