<?php

namespace iikiti\CMS\Enum;

use InvalidArgumentException;
use RuntimeException;

abstract class DynamicEnumerator implements DynamicEnumInterface {

	/** @var array<int|string,EnumCase> $cases */
	protected static array $cases = [];

	/** @var array<string,EnumCase> $casesByName */
	protected static array $casesByName = [];

	private function __construct() {}

	public static function get(string $name): ?EnumCase {
		return self::$casesByName[$name] ?? null;
	}

	public static function register(string $name, int|string|null $value = null): EnumCase {
 		if($value !== null)
			throw new InvalidArgumentException('Value must be null: It is not used');

		if(isset(self::$casesByName[$name]))
			throw new RuntimeException('Case with name "'. $name . '" already exists');

		return (
			self::$cases[] =
				self::$casesByName[$name] =
				new EnumCase($name, count(self::$cases) - 1)
		);
	}

	public static function cases(bool $byValue = false): array {
		return $byValue ? self::$cases : self::$casesByName;
	}

}
