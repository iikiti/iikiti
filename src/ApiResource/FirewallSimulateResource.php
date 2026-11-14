<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\ApiResource\Output\FirewallSimulateOutput;
use iikiti\CMS\State\Processor\FirewallSimulateProcessor;

/**
 * Dry-run simulator: evaluates a hypothetical request against every firewall stage without mutating
 * live counters, strikes, bans, or audit records.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	output: FirewallSimulateOutput::class,
	operations: [
	new Post(
		uriTemplate: '/admin/security/firewall/simulate',
		name: 'admin_firewall_simulate',
		processor: FirewallSimulateProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Post(
		uriTemplate: '/site/{siteId}/security/firewall/simulate',
		name: 'site_firewall_simulate',
		processor: FirewallSimulateProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallSimulateResource
{
	/** @param array<string,string> $headers */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?string $id = null,
		public string $ip = '',
		public string $method = 'GET',
		public string $path = '/',
		public array $headers = [],
		public string $userAgent = '',
		public bool $authenticated = false,
		public string $decision = '',
		/** @var array<string,mixed> */
		public array $ipMatch = [],
		/** @var array<string,mixed> */
		public array $botMatch = [],
		/** @var array<string,mixed> */
		public array $dosResult = [],
		/** @var list<array<string,mixed>> */
		public array $rateLimits = [],
		/** @var list<array<string,mixed>> */
		public array $wafFindings = [],
	) {
	}
}
