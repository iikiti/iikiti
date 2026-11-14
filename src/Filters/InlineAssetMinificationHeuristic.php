<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

final class InlineAssetMinificationHeuristic
{
	public static function isAlreadyMinified(string $source): bool
	{
		return 1 !== preg_match('/\/\*|(^|[^:])\/\/|<!--|[\r\n]|\h{2,}/', $source);
	}
}
