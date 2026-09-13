<?php

namespace iikiti\CMS\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;

/**
 * Common contract for validated SQL identifiers.
 *
 * An identifier is a column, table or alias. Every identifier is validated
 * against a conservative pattern so that a user-supplied value can never break
 * out of its position and be parsed as SQL syntax.
 */
interface IdentifierInterface extends \Stringable
{
	/**
	 * The validated identifier value, without quoting.
	 */
	public function getValue(): string;

	/**
	 * Reject an identifier that does not match the allowed pattern.
	 *
	 * @throws IdentifierValidationException
	 */
	public static function assertValid(string $value): void;
}
