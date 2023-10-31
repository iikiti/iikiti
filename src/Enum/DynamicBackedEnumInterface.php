<?php

namespace iikiti\CMS\Enum;

interface DynamicBackedEnumInterface {

	public static function get(string $name): ?EnumCase;

	public static function register(string $name, int|string|null $value = null): EnumCase;

	public static function from(int|string $value): EnumCase;

	public static function tryFrom(int|string $value): ?EnumCase;

}
