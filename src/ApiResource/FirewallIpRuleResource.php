<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\State\Processor\FirewallIpRuleDeleteProcessor;
use iikiti\CMS\State\Processor\FirewallIpRuleProcessor;
use iikiti\CMS\State\Provider\FirewallIpRuleProvider;

/**
 * Managed IP/CIDR allow, block, or report rule. Global rules use `siteId` 0 and are admin-only;
 * site rules are scoped to the request-host site and manageable by site managers.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
		new GetCollection(
			uriTemplate: '/admin/security/firewall/ip-rules',
			name: 'admin_firewall_ip_rules_get',
			provider: FirewallIpRuleProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/security/firewall/ip-rules',
			name: 'admin_firewall_ip_rules_post',
			processor: FirewallIpRuleProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/security/firewall/ip-rules/{id}',
			name: 'admin_firewall_ip_rule_get',
			provider: FirewallIpRuleProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/security/firewall/ip-rules/{id}',
			name: 'admin_firewall_ip_rule_put',
			provider: FirewallIpRuleProvider::class,
			processor: FirewallIpRuleProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Delete(
			uriTemplate: '/admin/security/firewall/ip-rules/{id}',
			name: 'admin_firewall_ip_rule_delete',
			provider: FirewallIpRuleProvider::class,
			processor: FirewallIpRuleDeleteProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new GetCollection(
			uriTemplate: '/site/{siteId}/security/firewall/ip-rules',
			name: 'site_firewall_ip_rules_get',
			provider: FirewallIpRuleProvider::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
		new Post(
			uriTemplate: '/site/{siteId}/security/firewall/ip-rules',
			name: 'site_firewall_ip_rules_post',
			processor: FirewallIpRuleProcessor::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
		new Get(
			uriTemplate: '/site/{siteId}/security/firewall/ip-rules/{id}',
			name: 'site_firewall_ip_rule_get',
			provider: FirewallIpRuleProvider::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
		new Put(
			uriTemplate: '/site/{siteId}/security/firewall/ip-rules/{id}',
			name: 'site_firewall_ip_rule_put',
			provider: FirewallIpRuleProvider::class,
			processor: FirewallIpRuleProcessor::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
		new Delete(
			uriTemplate: '/site/{siteId}/security/firewall/ip-rules/{id}',
			name: 'site_firewall_ip_rule_delete',
			provider: FirewallIpRuleProvider::class,
			processor: FirewallIpRuleDeleteProcessor::class,
			security: 'is_granted("ROLE_SITE_MANAGER")',
		),
	],
)]
final class FirewallIpRuleResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $id = null,
		public int|string|null $siteId = null,
		public string $cidr = '',
		public ?string $ipVersion = null,
		public string $action = 'block',
		public string $inheritance = 'default',
		public string $reason = '',
		public bool $enabled = true,
		public ?string $expiresAt = null,
		public int $priority = 0,
		public ?string $createdAt = null,
	) {
	}
}
