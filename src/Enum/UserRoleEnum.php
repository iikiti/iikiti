<?php

namespace iikiti\CMS\Enum;

/**
 * Handles an arbitrary number of user roles.
 */
final class UserRoleEnum extends DynamicBackedEnumerator
{
	/** @var array<string,EnumCase> */
	protected static array $defaultRoles = [];

	public static function registerDefault(string|int $role): void
	{
		$role = self::from($role);
		self::$defaultRoles = array_merge([$role->getName() => $role], self::$defaultRoles);
	}

	/**
	 * @return array<string,EnumCase>
	 */
	public static function getDefaultRoles(): array
	{
		return self::$defaultRoles;
	}
}

UserRoleEnum::register('Non-Member', 'ROLE_NON_MEMBER');
UserRoleEnum::register('Member', 'ROLE_MEMBER');
UserRoleEnum::register('Author', 'ROLE_AUTHOR');
UserRoleEnum::register('Editor', 'ROLE_EDITOR');
UserRoleEnum::register('Manager', 'ROLE_MANAGER');
UserRoleEnum::register('Site Manager', 'ROLE_SITE_MANAGER');
UserRoleEnum::register('Admin', 'ROLE_ADMIN');
UserRoleEnum::register('System', 'ROLE_SYSTEM');
UserRoleEnum::register('User', 'ROLE_USER');
UserRoleEnum::registerDefault('ROLE_USER');
UserRoleEnum::register('Super Administrator', 'ROLE_SUPER_ADMIN');
