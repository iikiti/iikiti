<?php
namespace iikiti\CMS\Manager;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Enum\EnumCase;
use iikiti\CMS\Enum\UserRoleEnum;



class UserRoleManager {

    private function __construct() {}

	public static function getRoles(User $user): array {
		return $user->getRoles();
	}

	/**
	 * @return array<int|string,string>
	 */
	public static function getRolesAsString(User $user): array {
		return array_map(fn(EnumCase $case) => (string) $case, static::getRoles($user));
	}

	public static function getDefaultRoles(): array {
		return UserRoleEnum::cases();
	}

}
