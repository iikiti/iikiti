<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

/**
 * Read-only fallback base class for persisted {@see DbObject} rows whose
 * concrete plugin subtype is no longer loaded in the current container.
 *
 * Concrete per-discriminator subclasses are generated into the cache directory
 * so Doctrine ORM maintains a strict 1-to-1 STI discriminator map without
 * triggering multi-discriminator deprecations or hydration crashes.
 */
abstract class UnknownDbObject extends DbObject
{
	abstract public static function getUnknownDiscriminator(): string;

	public function getOriginalType(): string
	{
		return $this->getType() ?? static::getUnknownDiscriminator();
	}
}
