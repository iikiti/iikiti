<?php

namespace iikiti\CMS\Enum;
use InvalidArgumentException;
use RuntimeException;



abstract class DynamicBackedEnumerator
	extends DynamicEnumerator
	implements DynamicBackedEnumInterface
{

	public static function from(int|string $value): EnumCase {
		$ref = new \ReflectionClass(static::class);
		return static::$cases[$value] ?? throw new RuntimeException(
			'"' . $value . '" is not a valid backing value for dynamic enum ' . $ref->getShortName()
		);
	}

	public static function tryFrom(int|string $value): ?EnumCase {
		return static::$cases[$value] ?? null;
	}

	public static function register(string $name, int|string|null $value = null): EnumCase {
		if($value === null)
		   throw new InvalidArgumentException('Value must be int or string, null given');

		return (self::$cases[$value] = self::$casesByName[$name] = new EnumCase($name, $value));
   }

}
