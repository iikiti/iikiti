<?php

namespace iikiti\CMS\Authentication\TokenGenerator;

use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

/**
 * Generates and validates numeric tokens.
 */
abstract class NumericTokenGenerator extends AbstractSimpleTokenGenerator
{
	/**
	 * @param array<string,mixed> $options
	 */
	protected function _generate(array $options = []): int
	{
		return random_int($options['min'], $options['max']);
	}

	protected static function _generateOptionsResolver(): OptionsResolver
	{
		$resolver = new OptionsResolver();
		$resolver->setDefaults(
			[
				'min' => 5,
				'max' => 5,
			]
		);
		$resolver->addAllowedTypes('min', 'int');
		$resolver->addAllowedTypes('max', 'int');
		$resolver->addAllowedValues(
			'min',
			Validation::createIsValidCallable(
				new Range(min: 0, max: PHP_INT_MAX)
			)
		);
		$resolver->addAllowedValues(
			'max',
			Validation::createIsValidCallable(
				new Range(min: 0, max: PHP_INT_MAX)
			)
		);
		$resolver->addNormalizer('max', function (Options $options, int $value): int {
			if ($value < $options['min']) {
				throw new InvalidOptionsException('Max must not be less than min.');
			}

			return $value;
		});

		return $resolver;
	}

	public function validate(
		#[\SensitiveParameter] int|string $requestToken,
		#[\SensitiveParameter] int|string $storedToken,
	): ConstraintViolationListInterface {
		$validator = Validation::createValidator();
		$intConstraint = new Type('int', 'The token must be an integer.');
		$tokenValidConstraint = new IsTrue(null, 'The token is not valid.');

		$constraints = new Collection(
			[
				'requestToken' => $intConstraint,
				'storedToken' => $intConstraint,
				'tokenValid' => $tokenValidConstraint,
			]
		);

		return $validator->validate(
			[
				'requestToken' => $requestToken,
				'storedToken' => $storedToken,
				'tokenValid' => password_verify((string) $storedToken, (string) $requestToken),
			],
			$constraints
		);
	}
}
