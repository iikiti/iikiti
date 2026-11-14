<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\State\Processor\FirewallIpBanDeleteProcessor;
use iikiti\CMS\State\Processor\FirewallIpBanProcessor;
use iikiti\CMS\State\Provider\FirewallIpBanProvider;

/**
 * Active temporary or permanent IP/CIDR bans created by auto-ban escalation or an operator.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new GetCollection(
		uriTemplate: '/admin/security/firewall/bans',
		name: 'admin_firewall_bans_get',
		provider: FirewallIpBanProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Post(
		uriTemplate: '/admin/security/firewall/bans',
		name: 'admin_firewall_bans_post',
		processor: FirewallIpBanProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Delete(
		uriTemplate: '/admin/security/firewall/bans/{id}',
		name: 'admin_firewall_ban_delete',
		provider: FirewallIpBanProvider::class,
		processor: FirewallIpBanDeleteProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new GetCollection(
		uriTemplate: '/site/{siteId}/security/firewall/bans',
		name: 'site_firewall_bans_get',
		provider: FirewallIpBanProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Delete(
		uriTemplate: '/site/{siteId}/security/firewall/bans/{id}',
		name: 'site_firewall_ban_delete',
		provider: FirewallIpBanProvider::class,
		processor: FirewallIpBanDeleteProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallIpBanResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $id = null,
		public int|string|null $siteId = null,
		public string $cidr = '',
		public string $reasonCode = '',
		public string $reasonMessage = '',
		public int $strikes = 1,
		public ?string $expiresAt = null,
		public ?string $createdAt = null,
		/** Operator-created bans set this to ban an IP/CIDR; read-only on collection output. */
		public ?int $ttlSeconds = null,
	) {
	}
}
