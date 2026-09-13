<?php

namespace iikiti\CMS\Query;

/**
 * The type of SQL statement a query builder represents.
 *
 * Doctrine DBAL keeps an internal, non-public query type enum. This public
 * mirror is exposed so application code and plugins can reason about the shape
 * of a builder (select, update or delete) without depending on DBAL internals.
 */
enum QueryType: string
{
	case SELECT = 'select';
	case UPDATE = 'update';
	case DELETE = 'delete';
}
