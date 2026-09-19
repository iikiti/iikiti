<?php

namespace iikiti\CMS\Search\Exception;

/**
 * Thrown when a requested search index configuration does not exist.
 */
class IndexNotFoundException extends SearchException
{
	public static function fromSlug(string $slug): self
	{
		return new self(sprintf('Search index "%s" was not found.', $slug));
	}

	public static function fromId(int|string $id): self
	{
		return new self(sprintf('Search index with id "%s" was not found.', $id));
	}
}
