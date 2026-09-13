<?php

namespace iikiti\CMS\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;

/**
 * Shared identifier validation.
 *
 * An identifier may be a bare name (`id`), a qualified name (`u.id`) or a
 * schema-qualified name (`public.objects`). Every part must start with a
 * letter or underscore and continue with letters, digits or underscores.
 */
trait ValidatesIdentifier
{
	private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,2}$/';

	public static function assertValid(string $value): void
	{
		if (1 !== preg_match(self::PATTERN, $value)) {
			throw new IdentifierValidationException(sprintf('"%s" is not a valid SQL identifier. Identifiers may contain letters, digits and underscores, optionally qualified with a dot.', $value));
		}
	}
}
