<?php

namespace iikiti\CMS\Manager;

use iikiti\CMS\Enum\EnumCase;
use iikiti\CMS\Enum\UserRoleEnum;

/**
 * Manage user role enumerations.
 */
class UserRoleManager
{
	private function __construct()
	{
	}

	/**
	 * @return array<string,EnumCase>
	 */
	public static function getDefaultRoles(): array
	{
		return UserRoleEnum::getDefaultRoles();
	}

	/**
	 * @return array<int|string,EnumCase>
	 */
	public static function getAllRoles(): array
	{
		return UserRoleEnum::cases();
	}

	/**
	 * @param array<string,int|bool|string> $roles
	 *
	 * @return array<string,EnumCase>
	 */
	public static function convertStringsToEnums(array $roles): array
	{
		$newRoles = [];
		foreach ($roles as $role) {
			$roleEnum = UserRoleEnum::tryFrom($role);
			if (null === $roleEnum) {
				continue;
			}
			$newRoles[$roleEnum->getName()] = $roleEnum;
		}

		return $newRoles;
	}

	/**
	 * @param array<string,EnumCase> $roles
	 *
	 * @return array<string,string>
	 */
	public static function convertEnumsToStrings(array $roles): array
	{
		return array_map(fn (EnumCase $role): string => (string) $role->getValue(), $roles);
	}
}
