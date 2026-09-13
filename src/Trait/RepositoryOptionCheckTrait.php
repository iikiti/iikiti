<?php

namespace iikiti\CMS\Trait;

/**
 * Trait providing convenience methods for checking options, types, and
 * providing defaults.
 */
trait RepositoryOptionCheckTrait
{
	protected function _typeCheck_bool(mixed $value, bool $allowNull = true): bool
	{
		return is_bool($value) || (null === $value && $allowNull);
	}

	protected function _typeCheck_stringOrArray(mixed $value, bool $allowNull = true): bool
	{
		return (is_string($value) || is_array($value)) || (null === $value && $allowNull);
	}

	protected function _typeCheck_positiveInt(mixed $value, bool $allowNull = true): bool
	{
		return (is_int($value) && $value > 0) || (null === $value && $allowNull);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	protected static function _checkOption(
		string $key,
		array $options,
		?\Closure $typeCheck
	): mixed {
		$default = self::_defaultOption($key);
		$value = $options[$key] ?? null;
		if (null !== $typeCheck && !$typeCheck($value)) {
			throw new \InvalidArgumentException('Type is incorrect for key: '.$key);
		}

		return $value ?? $default;
	}

	protected static function _defaultOption(string $key): bool|string|int|null
	{
		/** @var array<string,bool|string|int|null> $values */
		static $values = [
			'filterBySite' => true,
			'includeRevisions' => false,
			'cache' => null,
			'cacheTTL' => null,
		];

		return $values[$key] ?? null;
	}
}
