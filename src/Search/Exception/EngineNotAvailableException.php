<?php

namespace iikiti\CMS\Search\Exception;

/**
 * Thrown when no search engine adapter is available for a given configuration
 * or database platform.
 */
class EngineNotAvailableException extends SearchException
{
	public static function forEngine(string $engine): self
	{
		return new self(sprintf('No search engine adapter is available for "%s".', $engine));
	}

	public static function forPlatform(string $platform): self
	{
		return new self(sprintf('No search engine adapter supports the "%s" database platform.', $platform));
	}
}
