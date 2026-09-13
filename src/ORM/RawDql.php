<?php

namespace iikiti\CMS\ORM;

/**
 * An explicitly trusted DQL fragment.
 *
 * Wraps free-form DQL so it is exempt from the query builder's inline-value
 * scan. Use only for fragments that genuinely cannot be expressed through the
 * typed expression builder.
 */
final class RawDql implements \Stringable
{
	public function __construct(private readonly string $dql)
	{
	}

	public function getDql(): string
	{
		return $this->dql;
	}

	public function __toString(): string
	{
		return $this->dql;
	}
}
