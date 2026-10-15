<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class MfaFactorProviderRegistry
{
	/** @var array<string,MfaFactorProviderInterface> */
	private array $providers = [];

	/** @param iterable<MfaFactorProviderInterface> $providers */
	public function __construct(
		#[AutowireIterator('mfa.factor_provider')]
		iterable $providers = [],
	) {
		foreach ($providers as $provider) {
			$key = $provider->getKey();
			$proofKey = $provider->getProofKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $key) ||
				!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $proofKey) ||
				isset($this->providers[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate MFA factor key "%s".', $key));
			}
			$this->providers[$key] = $provider;
		}
	}

	public function get(string $key): ?MfaFactorProviderInterface
	{
		return $this->providers[$key] ?? null;
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->providers);
	}

	/** @param list<string> $factorKeys
	 * @return list<string>
	 */
	public function proofKeysFor(array $factorKeys): array
	{
		$proofs = [];
		foreach ($factorKeys as $factorKey) {
			$provider = $this->get($factorKey);
			if (null === $provider) {
				throw new \LogicException(sprintf('MFA factor "%s" is not registered.', $factorKey));
			}
			$proofs[] = $provider->getProofKey();
		}

		return array_values(array_unique($proofs));
	}

	/** @param list<string> $factorKeys
	 * @return array<string,string>
	 */
	public function versionsFor(array $factorKeys): array
	{
		$versions = [];
		foreach ($factorKeys as $key) {
			$provider = $this->get($key);
			if (null === $provider) {
				throw new \LogicException(sprintf('MFA factor "%s" is not registered.', $key));
			}
			$versions[$key] = $provider->getVersion();
		}
		ksort($versions);

		return $versions;
	}
}
