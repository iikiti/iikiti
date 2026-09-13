<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Base exception for all query builder errors.
 *
 * Every exception thrown by the query builder layer extends this class so that
 * callers can catch a single type when handling query construction failures.
 */
class QueryBuilderException extends \RuntimeException
{
}
