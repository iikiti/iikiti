<?php

declare(strict_types=1);

namespace iikiti\CMS\Crypto;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Participates in `iikiti:security:keys:rotate` so plugins encrypting secrets
 * with application keyrings can re-encrypt stored records inside the same
 * database transaction.
 */
#[AutoconfigureTag('iikiti.crypto.key_rotator')]
interface ApplicationKeyRotatorInterface
{
	public function getLabel(): string;

	public function rotate(): int;
}
