<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\State\Processor\FirewallSitePolicyProcessor;
use iikiti\CMS\State\Provider\FirewallSitePolicyProvider;

#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
		new Get(
			uriTemplate: '/site/{siteId}/security/firewall/policy',
			name: 'site_firewall_policy_get',
			provider: FirewallSitePolicyProvider::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
		new Put(
			uriTemplate: '/site/{siteId}/security/firewall/policy',
			name: 'site_firewall_policy_put',
			provider: FirewallSitePolicyProvider::class,
			processor: FirewallSitePolicyProcessor::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
	],
)]
final class FirewallSitePolicyResource
{
	/** @param list<array<string,mixed>> $rules */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $siteId = null,
		public array $rules = [],
	) {
	}
}
