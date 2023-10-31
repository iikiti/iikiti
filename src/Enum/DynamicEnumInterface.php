<?php

namespace iikiti\CMS\Enum;

interface DynamicEnumInterface {

	public static function get(string $name): ?EnumCase;

	public static function register(string $name, int|string|null $value = null): EnumCase;

	public static function cases(): array;

}
