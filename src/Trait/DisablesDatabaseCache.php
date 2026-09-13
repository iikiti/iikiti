<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\CacheState;

/**
 * For console commands that perform batch processing.
 *
 * Commands that write many entities (imports, seeds, migrations) should call
 * disableDatabaseCache() so that every query bypasses the cache, avoiding stale
 * reads and excessive cache invalidation during the batch run.
 * 
 * @phpstan-ignore trait.unused (Provided by caching layer for developer use)
 */
trait DisablesDatabaseCache
{
	protected function disableDatabaseCache(CacheState $cacheState): void
	{
		$cacheState->disable();
	}
}
