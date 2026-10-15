<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

final class MfaProofPolicy
{
	public function __construct(private readonly MfaFactorProviderRegistry $factors)
	{
	}

	/** @param list<string> $factorKeys
	 * @return list<string>
	 */
	public function requiredProofKeys(array $factorKeys): array
	{
		return $this->factors->proofKeysFor($factorKeys);
	}

	/** @param list<string> $requiredProofKeys
	 * @param array<string,mixed> $context
	 */
	public function isSatisfied(array $requiredProofKeys, array $context): bool
	{
		foreach ($requiredProofKeys as $proofKey) {
			if (true !== ($context[$proofKey] ?? false)) {
				return false;
			}
		}

		return [] !== $requiredProofKeys;
	}
}
