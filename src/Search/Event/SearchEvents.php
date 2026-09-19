<?php

namespace iikiti\CMS\Search\Event;

/**
 * Events dispatched during the search lifecycle.
 */
final class SearchEvents
{
	public const SEARCH = 'iikiti.search.search';
	public const AUTOCOMPLETE = 'iikiti.search.autocomplete';
	public const FILTER_APPLY = 'iikiti.search.filter_apply';
	public const FILTER_RESOLVE = 'iikiti.search.filter_resolve';
	public const INDEX_CREATED = 'iikiti.search.index_created';
	public const INDEX_DROPPED = 'iikiti.search.index_dropped';
	public const INDEX_REBUILT = 'iikiti.search.index_rebuilt';
}
