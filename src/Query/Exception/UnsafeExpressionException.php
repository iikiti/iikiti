<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown when an expression cannot be validated for safety.
 *
 * This is used for structural problems (for example an expression that is
 * empty, or a composite that mixes incompatible parts) rather than for the
 * presence of an inline literal, which raises {@see InlineValueException}.
 */
class UnsafeExpressionException extends QueryBuilderException
{
}
