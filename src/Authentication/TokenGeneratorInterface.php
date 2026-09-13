<?php

namespace iikiti\CMS\Authentication;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Generates and validates short-lived tokens such as e-mailed codes.
 */
interface TokenGeneratorInterface
{
	/**
	 * @param array<string,mixed> $options
	 */
	public function generate(array $options = []): string|int;

	public function validate(string|int $requestToken, string|int $storedToken): ConstraintViolationListInterface;
}
