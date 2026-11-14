<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

/**
 * Immutable description of a known bot, AI crawler, agent, or scanner.
 *
 * verificationMethod is one of 'cidr_pack', 'fcrdns', or 'none'.
 */
final readonly class FirewallBotDefinition
{
	/**
	 * @param list<string> $userAgentPatterns case-insensitive substrings matched against the User-Agent
	 * @param list<string> $fcrdnsDomains    allowed reverse-DNS suffixes (label-boundary matched)
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $category,
		public array $userAgentPatterns,
		public string $verificationMethod,
		public ?string $cidrPackSourceKey,
		public array $fcrdnsDomains,
		public string $description,
	) {
	}

	public function matchesUserAgent(string $userAgent): bool
	{
		foreach ($this->userAgentPatterns as $pattern) {
			if ('' !== $pattern && false !== stripos($userAgent, $pattern)) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'category' => $this->category,
			'userAgentPatterns' => $this->userAgentPatterns,
			'verificationMethod' => $this->verificationMethod,
			'cidrPackSourceKey' => $this->cidrPackSourceKey,
			'fcrdnsDomains' => $this->fcrdnsDomains,
			'description' => $this->description,
		];
	}
}
