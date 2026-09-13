<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown for common table expression (CTE) problems.
 *
 * Typical causes are duplicate CTE names, empty names, or a CTE that
 * references another CTE declared later in the same set.
 */
class CteException extends QueryBuilderException
{
	public static function duplicate(string $name): self
	{
		return new self(sprintf('Common table expression "%s" is defined more than once.', $name));
	}

	public static function forwardReference(string $name, string $referenced): self
	{
		return new self(sprintf(
			'Common table expression "%s" references "%s", which is declared later. '.
			'CTEs must be ordered before they are referenced.',
			$name,
			$referenced
		));
	}

	public static function invalidName(string $name): self
	{
		return new self(sprintf('"%s" is not a valid common table expression name.', $name));
	}
}
