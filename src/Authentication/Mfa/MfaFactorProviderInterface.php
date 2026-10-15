<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Workflow\FormStepInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('mfa.factor_provider')]
interface MfaFactorProviderInterface
{
	public function getKey(): string;

	public function getProofKey(): string;

	public function getVersion(): string;

	public function isAvailableFor(User $user): bool;

	/** @param array<string,mixed> $context */
	public function createStep(string $placeId, array $context): ?FormStepInterface;
}
