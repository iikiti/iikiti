<?php

namespace iikiti\CMS\Search\Enum;

/**
 * The type of a search index configuration.
 */
enum SearchIndexType: string
{
	case Frontend = 'frontend';
	case Admin = 'admin';
	case Custom = 'custom';

	public function getLabel(): string
	{
		return match ($this) {
			self::Frontend => 'Front-end Search',
			self::Admin => 'Administration Search',
			self::Custom => 'Custom Index',
		};
	}

	/**
	 * Whether configurations of this type can be deleted.
	 */
	public function isDeletable(): bool
	{
		return self::Custom === $this || self::Frontend === $this;
	}
}
