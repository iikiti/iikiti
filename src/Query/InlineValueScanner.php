<?php

namespace iikiti\CMS\Query;

use iikiti\CMS\Query\Exception\InlineValueException;

/**
 * Static inline-value detector for raw SQL/DQL expression strings.
 *
 * Both the DBAL and ORM query builders delegate to this so the safety
 * contract is enforced consistently. It rejects quoted string literals
 * (including PostgreSQL dollar-quoted strings), numeric literals in their
 * common forms, and boolean/NULL keyword literals, while recognising
 * parameter placeholders and the `IS [NOT] NULL` operator forms.
 */
final class InlineValueScanner
{
	/**
	 * Scan an expression string and throw when an inline literal is present.
	 *
	 * @throws InlineValueException
	 */
	public static function assertSafe(string $expression, bool $enabled): void
	{
		if (!$enabled || '' === $expression) {
			return;
		}

		// Remove parameter placeholders so a digit inside a name such as
		// `:qp1` is never mistaken for a literal.
		$stripped = preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '', $expression) ?? $expression;
		$stripped = str_replace('?', '', $stripped);

		if (1 === preg_match("/'[^']*'/", $stripped, $matches)) {
			throw InlineValueException::forExpression($expression, $matches[0]);
		}

		if (1 === preg_match('/"[^"]*"/', $stripped, $matches)) {
			throw InlineValueException::forExpression($expression, $matches[0]);
		}

		// PostgreSQL dollar-quoted strings (`$$…$$` or `$tag$…$tag$`).
		if (1 === preg_match('/\$[A-Za-z0-9_]*\$/', $stripped, $matches)) {
			throw InlineValueException::forExpression($expression, $matches[0]);
		}

		// Numeric literals, including hexadecimal, octal and binary forms,
		// digit separators and scientific notation. The alternatives are
		// ordered so `0x…` / `0o…` / `0b…` prefixes are matched before the
		// decimal branch.
		if (1 === preg_match(
			'/\b(?:0[xX][0-9A-Fa-f_]+|0[oO][0-7_]+|0[bB][01_]+|\d[\d_]*(?:\.[\d_]*)?(?:[eE][+-]?\d+)?)\b/',
			$stripped,
			$matches
		)) {
			throw InlineValueException::forExpression($expression, $matches[0]);
		}

		// Boolean and NULL keyword literals, excluding the `IS [NOT]`
		// operator forms which are legitimate SQL/DQL.
		$withoutNullChecks = preg_replace('/\bIS\s+(?:NOT\s+)?(?:NULL|TRUE|FALSE)\b/i', '', $stripped) ?? $stripped;
		if (1 === preg_match('/\b(?:TRUE|FALSE|NULL)\b/i', $withoutNullChecks, $matches)) {
			throw InlineValueException::forExpression($expression, $matches[0]);
		}
	}
}
