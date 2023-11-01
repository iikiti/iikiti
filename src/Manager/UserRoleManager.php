<?php
namespace iikiti\CMS\Manager;

use iikiti\CMS\Enum\EnumCase;
use iikiti\CMS\Enum\UserRoleEnum;

class UserRoleManager {

    private function __construct() {}

	public static function getDefaultRoles(): array {
		return UserRoleEnum::cases();
	}

	/**
	 * @return array<string,EnumCase>
	 */
	public static function convertStringsToEnums(array $roles): array {
		$newRoles = [];
		foreach($roles as $role) {
			$roleEnum = UserRoleEnum::tryFrom($role);
			if($roleEnum === null) continue;
			$newRoles[$roleEnum->getName()] = $roleEnum;
		}
		return $newRoles;
	}

	/**
	 * @param array<string,EnumCase> $roles
	 * @return array<string,string>
	 */
	public static function convertEnumsToStrings(array $roles): array {
		return array_map(fn(EnumCase $role): string => (string) $role->getValue(), $roles);
	}

}
