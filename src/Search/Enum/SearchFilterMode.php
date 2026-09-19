<?php

namespace iikiti\CMS\Search\Enum;

/**
 * When a search filter is applied relative to the search pipeline.
 */
enum SearchFilterMode: string
{
	case QueryTime = 'query_time';
	case IndexTime = 'index_time';

	public function getLabel(): string
	{
		return match ($this) {
			self::QueryTime => 'Query-time',
			self::IndexTime => 'Index-time',
		};
	}
}
