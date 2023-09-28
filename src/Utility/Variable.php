<?php

namespace iikiti\CMS\Utility;

abstract class Variable{

	public static function is_true(mixed $val): ?bool {
		return is_string($val) ?
			filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) :
			(bool) $val;
	}

}