<?php

namespace iikiti\CMS\Authentication\TokenGenerator;

/**
 * Generates and validates random integer tokens.
 */
class IntegerTokenGenerator extends NumericTokenGenerator
{
	public function generate(array $options = []): int
	{
		$options = $this->optionsResolver->resolve($options);

		return $this->_generate($options);
	}
}
