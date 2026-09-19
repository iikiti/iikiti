<?php

namespace iikiti\CMS\Search\Enum;

/**
 * How a search index field sources its data.
 */
enum SearchFieldSourceType: string
{
	case Column = 'column';
	case Property = 'property';
	case Virtual = 'virtual';
	case Alias = 'alias';

	public function getLabel(): string
	{
		return match ($this) {
			self::Column => 'Database Column',
			self::Property => 'Object Property',
			self::Virtual => 'Virtual Column',
			self::Alias => 'Alias',
		};
	}

	public function isResolvable(): bool
	{
		return self::Column === $this || self::Property === $this || self::Virtual === $this;
	}
}
