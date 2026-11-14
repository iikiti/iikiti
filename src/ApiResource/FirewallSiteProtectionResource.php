<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\State\Processor\FirewallSiteProtectionProcessor;
use iikiti\CMS\State\Provider\FirewallSiteProtectionProvider;

/**
 * Site-level firewall protection overrides plus the merged `effective` policy for UI visibility.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new Get(
		uriTemplate: '/site/{siteId}/security/firewall/protection',
		name: 'site_firewall_protection_get',
		provider: FirewallSiteProtectionProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Put(
		uriTemplate: '/site/{siteId}/security/firewall/protection',
		name: 'site_firewall_protection_put',
		provider: FirewallSiteProtectionProvider::class,
		processor: FirewallSiteProtectionProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallSiteProtectionResource
{
	/**
	 * @param list<array<string,mixed>> $rateLimits
	 * @param array<string,mixed>       $dos
	 * @param array<string,mixed>       $bots
	 * @param array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}|null $effective
	 */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $siteId = null,
		public int $version = 1,
		public array $rateLimits = [],
		public array $dos = [],
		public array $bots = [],
		public ?array $effective = null,
	) {
	}
}
