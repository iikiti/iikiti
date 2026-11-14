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
use iikiti\CMS\State\Processor\FirewallTrustedAutomationDeleteProcessor;
use iikiti\CMS\State\Processor\FirewallTrustedAutomationProcessor;
use iikiti\CMS\State\Provider\FirewallTrustedAutomationProvider;

/**
 * Operator-defined trusted automation (webhook, CI, approved AI/MCP agent).
 *
 * `secret` is write-only: it is accepted on create/update, transformed server-side, and never
 * returned. Output exposes only `hasSecret`.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new GetCollection(
		uriTemplate: '/admin/security/firewall/automations',
		name: 'admin_firewall_automations_get',
		provider: FirewallTrustedAutomationProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Post(
		uriTemplate: '/admin/security/firewall/automations',
		name: 'admin_firewall_automations_post',
		processor: FirewallTrustedAutomationProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Get(
		uriTemplate: '/admin/security/firewall/automations/{id}',
		name: 'admin_firewall_automation_get',
		provider: FirewallTrustedAutomationProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Put(
		uriTemplate: '/admin/security/firewall/automations/{id}',
		name: 'admin_firewall_automation_put',
		provider: FirewallTrustedAutomationProvider::class,
		processor: FirewallTrustedAutomationProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new Delete(
		uriTemplate: '/admin/security/firewall/automations/{id}',
		name: 'admin_firewall_automation_delete',
		provider: FirewallTrustedAutomationProvider::class,
		processor: FirewallTrustedAutomationDeleteProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
	new GetCollection(
		uriTemplate: '/site/{siteId}/security/firewall/automations',
		name: 'site_firewall_automations_get',
		provider: FirewallTrustedAutomationProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Post(
		uriTemplate: '/site/{siteId}/security/firewall/automations',
		name: 'site_firewall_automations_post',
		processor: FirewallTrustedAutomationProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Get(
		uriTemplate: '/site/{siteId}/security/firewall/automations/{id}',
		name: 'site_firewall_automation_get',
		provider: FirewallTrustedAutomationProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Put(
		uriTemplate: '/site/{siteId}/security/firewall/automations/{id}',
		name: 'site_firewall_automation_put',
		provider: FirewallTrustedAutomationProvider::class,
		processor: FirewallTrustedAutomationProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
	new Delete(
		uriTemplate: '/site/{siteId}/security/firewall/automations/{id}',
		name: 'site_firewall_automation_delete',
		provider: FirewallTrustedAutomationProvider::class,
		processor: FirewallTrustedAutomationDeleteProcessor::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallTrustedAutomationResource
{
	/**
	 * @param list<string> $cidrs
	 * @param list<string> $allowedPaths
	 * @param array{limit:int,interval:int}|null $rateLimitOverride
	 */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public int|string|null $id = null,
		public int|string|null $siteId = null,
		public string $name = '',
		public string $handle = '',
		public string $category = 'custom_automation',
		public string $authMode = 'cidr',
		public array $cidrs = [],
		public ?string $headerName = null,
		public ?string $publicKey = null,
		/** Write-only. Always null on output. */
		public ?string $secret = null,
		/** Output-only: whether a secret is stored. */
		public bool $hasSecret = false,
		public array $allowedPaths = [],
		public bool $bypassWaf = false,
		public ?array $rateLimitOverride = null,
		public bool $enabled = true,
		public int $priority = 0,
	) {
	}
}
