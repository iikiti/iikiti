<?php

namespace iikiti\CMS\Authentication\TokenGenerator;

use iikiti\CMS\Authentication\TokenGeneratorInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Base class for token generators. Builds the shared options resolver.
 */
abstract class AbstractTokenGenerator implements TokenGeneratorInterface
{
	protected OptionsResolver $optionsResolver;

	public function __construct()
	{
		$this->optionsResolver = static::_generateOptionsResolver();
	}

	abstract protected static function _generateOptionsResolver(): OptionsResolver;
}
