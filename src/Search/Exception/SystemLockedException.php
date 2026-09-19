<?php

namespace iikiti\CMS\Search\Exception;

/**
 * Thrown when an operation is attempted on a system-locked search index
 * configuration that cannot be deleted (e.g. the administration search config).
 */
class SystemLockedException extends SearchException
{
	public static function cannotDelete(string $name): self
	{
		return new self(sprintf('Search index "%s" is system-locked and cannot be deleted.', $name));
	}

	public static function cannotChangeEngine(string $name): self
	{
		return new self(sprintf('Search index "%s" is system-locked; its engine cannot be changed.', $name));
	}
}
