<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource\Output;

final readonly class FirewallSimulateOutput
{
	/**
	 * @param array<string,mixed>      $ipMatch
	 * @param array<string,mixed>      $botMatch
	 * @param array<string,mixed>      $dosResult
	 * @param list<array<string,mixed>> $rateLimits
	 * @param list<array<string,mixed>> $wafFindings
	 */
	public function __construct(
		public string $ip,
		public string $method,
		public string $path,
		public bool $authenticated,
		public string $decision,
		public array $ipMatch,
		public array $botMatch,
		public array $dosResult,
		public array $rateLimits,
		public array $wafFindings,
	) {
	}
}
