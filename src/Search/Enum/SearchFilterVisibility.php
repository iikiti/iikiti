<?php

namespace iikiti\CMS\Search\Enum;

/**
 * Who can see and use a search filter.
 */
enum SearchFilterVisibility: string
{
	case PublicFrontend = 'public_frontend';
	case AdminOnly = 'admin_only';
	case RoleRestricted = 'role_restricted';

	public function getLabel(): string
	{
		return match ($this) {
			self::PublicFrontend => 'Public (front-end)',
			self::AdminOnly => 'Administration only',
			self::RoleRestricted => 'Role-restricted',
		};
	}

	public function isVisibleToFrontend(): bool
	{
		return self::PublicFrontend === $this;
	}

	public function isVisibleToAdmin(): bool
	{
		return true;
	}
}
