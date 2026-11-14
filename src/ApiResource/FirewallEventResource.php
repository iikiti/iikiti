<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\FirewallEventProvider;

/**
 * Sanitized, bounded feed of recent firewall enforcement events. Never includes request bodies,
 * headers, cookies, or secrets.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new GetCollection(
		uriTemplate: '/admin/security/firewall/events',
		name: 'admin_firewall_events_get',
		provider: FirewallEventProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new GetCollection(
		uriTemplate: '/site/{siteId}/security/firewall/events',
		name: 'site_firewall_events_get',
		provider: FirewallEventProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallEventResource
{
	/** @param array<string,mixed> $context */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $id = null,
		public string $action = '',
		public string $summary = '',
		public ?string $ipAddress = null,
		public ?string $requestUri = null,
		public array $context = [],
		public ?string $createdAt = null,
	) {
	}
}
