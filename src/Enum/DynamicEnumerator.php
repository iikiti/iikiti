<?php

namespace iikiti\CMS\Enum;

/**
 * Dynamic enumerator allows for an arbitrary number of dynamically defined
 * cases.
 */
abstract class DynamicEnumerator implements DynamicEnumInterface
{
	/** @var array<int|string,EnumCase> */
	protected static array $cases = [];

	/** @var array<string,EnumCase> */
	protected static array $casesByName = [];

	private function __construct()
	{
	}

	public static function get(string $name): ?EnumCase
	{
		return self::$casesByName[$name] ?? null;
	}

	public static function register(string $name, int|string|null $value = null): EnumCase
	{
		if (null !== $value) {
			throw new \InvalidArgumentException('Value must be null: It is not used');
		}

		if (isset(self::$casesByName[$name])) {
			throw new \RuntimeException('Case with name "'.$name.'" already exists');
		}

		return
			self::$cases[] =
				self::$casesByName[$name] =
				new EnumCase($name, count(self::$cases) - 1)
		;
	}

	public static function cases(bool $byValue = false): array
	{
		return $byValue ? self::$cases : self::$casesByName;
	}
}
