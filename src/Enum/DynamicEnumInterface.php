<?php

namespace iikiti\CMS\Enum;

/**
 * Ensures dynamic enumeration classes implement required methods.
 */
interface DynamicEnumInterface
{
	public static function get(string $name): ?EnumCase;

	public static function register(string $name, int|string|null $value = null): EnumCase;

	public static function cases(): array;
}
