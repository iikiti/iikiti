<?php

declare(strict_types=1);

namespace iikiti\CMS\ORM\DQL\Function;

use Override;
use Scienta\DoctrineJsonFunctions\Query\AST\Functions\Postgresql\JsonbContains as BaseJsonbContains;

final class JsonbContains extends BaseJsonbContains
{
	/** @param list<string> $arguments */
	#[Override]
	protected function getSqlForArgs(array $arguments): string
	{
		[$left, $right] = $arguments;

		return sprintf('(CAST(%s AS JSONB) @> CAST(%s AS JSONB))', $left, $right);
	}
}
