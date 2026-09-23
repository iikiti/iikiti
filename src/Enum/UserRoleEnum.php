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

	/**
	 * Enforces the role value naming convention: must start with ROLE_ and
	 * contain only uppercase letters, digits, and underscores.
	 *
	 * @throws \InvalidArgumentException when the value does not match /^ROLE_[A-Z0-9_]+$/
	 */
	protected static function validateValue(string $name, int|string $value): void
	{
		if (!is_string($value) || !preg_match('/^ROLE_[A-Z0-9_]+$/', $value)) {
			throw new \InvalidArgumentException(sprintf('Role value "%s" must start with "ROLE_" and contain only uppercase letters, digits, and underscores.', $value));
		}
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
