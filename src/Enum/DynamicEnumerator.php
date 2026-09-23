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

	#[\Override]
	public static function get(string $name): ?EnumCase
	{
		return self::$casesByName[$name] ?? null;
	}

	#[\Override]
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

	#[\Override]
	/**
	 * @return array<int|string,EnumCase>
	 */
	public static function cases(bool $byValue = false): array
	{
		return $byValue ? self::$cases : self::$casesByName;
	}

	#[\Override]
	public static function has(string|int $value): bool
	{
		return array_key_exists($value, self::$cases);
	}

	#[\Override]
	public static function hasName(string $name): bool
	{
		return array_key_exists($name, self::$casesByName);
	}
}
