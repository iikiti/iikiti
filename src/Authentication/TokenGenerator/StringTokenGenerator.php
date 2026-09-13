<?php

namespace iikiti\CMS\Authentication\TokenGenerator;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

/**
 * Generates and validates random numeric tokens returned as strings.
 */
class StringTokenGenerator extends NumericTokenGenerator
{
	public function generate(array $options = []): string
	{
		$options = $this->optionsResolver->resolve($options);
		$returnString = '';
		for ($chrIdx = 0; $chrIdx < $options['length']; ++$chrIdx) {
			$returnString .= (string) parent::_generate($options);
		}

		return $returnString;
	}

	public function validate(
		#[\SensitiveParameter] string|int $requestToken,
		#[\SensitiveParameter] string|int $storedToken,
	): ConstraintViolationListInterface {
		$validator = Validation::createValidator();
		$tokenValidConstraint = new IsTrue(null, 'The token is not valid.');

		return $validator->validate(
			password_verify((string) $storedToken, (string) $requestToken),
			$tokenValidConstraint
		);
	}

	protected static function _generateOptionsResolver(): OptionsResolver
	{
		$resolver = parent::_generateOptionsResolver();
		$resolver->setDefault('length', 6);
		$resolver->addAllowedTypes('length', 'int');
		$resolver->addAllowedValues(
			'length',
			Validation::createIsValidCallable(
				new Range(min: 6, max: PHP_INT_MAX)
			)
		);

		return $resolver;
	}
}
