<?php

namespace iikiti\CMS\Authentication;

/**
 * Holds the data needed to verify a multi-factor challenge.
 *
 * @template T
 */
interface ChallengeInterface
{
	/**
	 * @return T
	 */
	public function get(): mixed;

	/**
	 * @param T $challenge
	 */
	public function set(#[\SensitiveParameter] mixed $challenge): void;
}
