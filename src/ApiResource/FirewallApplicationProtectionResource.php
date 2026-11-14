<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\State\Processor\FirewallApplicationProtectionProcessor;
use iikiti\CMS\State\Provider\FirewallApplicationProtectionProvider;

/**
 * Application-wide firewall protection policy: rate limits, L7 DoS settings, and bot/AI postures.
 * Entries marked `required` cannot be weakened by any site policy.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new Get(
		uriTemplate: '/admin/applications/{applicationId}/firewall/policy',
		name: 'admin_firewall_application_policy_get',
		provider: FirewallApplicationProtectionProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Put(
		uriTemplate: '/admin/applications/{applicationId}/firewall/policy',
		name: 'admin_firewall_application_policy_put',
		provider: FirewallApplicationProtectionProvider::class,
		processor: FirewallApplicationProtectionProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
])]
final class FirewallApplicationProtectionResource
{
	/**
	 * @param list<array<string,mixed>> $rateLimits
	 * @param array<string,mixed>       $dos
	 * @param array<string,mixed>       $bots
	 */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $applicationId = null,
		public int $version = 1,
		public array $rateLimits = [],
		public array $dos = [],
		public array $bots = [],
	) {
	}
}
