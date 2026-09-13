<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown when a SQL identifier (column, table or alias) fails validation.
 *
 * Identifiers are restricted to a conservative character set so that
 * user-supplied values can never be interpreted as SQL syntax when they are
 * used as an identifier. Values that legitimately need other characters must
 * opt out explicitly through the platform strategy's quoting.
 */
class IdentifierValidationException extends QueryBuilderException
{
}
