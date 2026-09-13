<?php

namespace iikiti\CMS\Query;

/**
 * Abstract SQL operator names understood by the query builder.
 *
 * Concrete SQL syntax is provided by the active
 * {@see DatabasePlatform\DatabasePlatformStrategyInterface}. Using an abstract
 * operator rather than a literal operator string keeps application code
 * portable across databases: the same {@see Expression\Comparison} renders as
 * `~` on PostgreSQL and as a `REGEXP` call on MySQL.
 */
enum Operator: string
{
	case EQ = 'eq';
	case NEQ = 'neq';
	case LT = 'lt';
	case LTE = 'lte';
	case GT = 'gt';
	case GTE = 'gte';

	case IS_NULL = 'isNull';
	case IS_NOT_NULL = 'isNotNull';

	case LIKE = 'like';
	case NOT_LIKE = 'notLike';

	case REGEX = 'regex';
	case NOT_REGEX = 'notRegex';
	case IREGEX = 'iregex';
	case NOT_IREGEX = 'notIregex';

	case JSON_EXTRACT = 'jsonExtract';
	case JSON_GET_TEXT = 'jsonGetText';
	case JSON_CONTAINS = 'jsonContains';

	case FTS_MATCH = 'ftsMatch';

	case ARRAY_CONTAINS = 'arrayContains';
	case ARRAY_OVERLAPS = 'arrayOverlaps';

	/**
	 * Whether the operator is a binary infix operator that expects a right
	 * hand side (as opposed to a postfix operator such as `IS NULL`).
	 */
	public function isBinary(): bool
	{
		return !in_array($this, [self::IS_NULL, self::IS_NOT_NULL], true);
	}
}
