<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class FirewallRulePackSourceRegistry
{
	/** @var array<string,FirewallRulePackSourceInterface> */
	private array $sources = [];

	/** @param iterable<FirewallRulePackSourceInterface> $sources */
	public function __construct(#[AutowireIterator('iikiti.security.firewall_rule_pack_source')] iterable $sources = [])
	{
		foreach ($sources as $source) {
			$key = $source->getKey();
			$url = parse_url($source->getUrl());
			$publicKey = base64_decode($source->getPublicKey(), true);
			if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key) || isset($this->sources[$key]) ||
				!is_array($url) || 'https' !== strtolower((string) ($url['scheme'] ?? '')) ||
				'' === (string) ($url['host'] ?? '') || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) ||
				false === $publicKey || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey)
			) {
				throw new \LogicException(sprintf('Invalid or duplicate signed firewall pack source "%s".', $key));
			}
			$this->sources[$key] = $source;
		}
	}

	public function get(string $key): FirewallRulePackSourceInterface
	{
		return $this->sources[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown firewall rule-pack source "%s".', $key));
	}

	/** @return list<FirewallRulePackSourceInterface> */
	public function all(): array
	{
		return array_values($this->sources);
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->sources);
	}
}
