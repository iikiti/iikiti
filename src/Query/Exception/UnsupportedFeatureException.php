<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown when a requested operator or feature is not available on the active
 * database platform strategy.
 */
class UnsupportedFeatureException extends QueryBuilderException
{
	public static function operator(string $operator, string $platform): self
	{
		return new self(sprintf(
			'Operator "%s" is not supported by the "%s" database platform strategy.',
			$operator,
			$platform
		));
	}

	public static function function(string $function, string $platform): self
	{
		return new self(sprintf(
			'Function "%s" is not supported by the "%s" database platform strategy.',
			$function,
			$platform
		));
	}

	public static function feature(string $feature, string $platform): self
	{
		return new self(sprintf(
			'Feature "%s" is not supported by the "%s" database platform strategy.',
			$feature,
			$platform
		));
	}
}
