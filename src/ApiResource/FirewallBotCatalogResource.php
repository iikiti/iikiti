<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\FirewallBotCatalogProvider;

/**
 * Read-only directory of known bots, AI agents, and their verification methods.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	operations: [
	new GetCollection(
		uriTemplate: '/admin/security/firewall/bots/catalog',
		name: 'admin_firewall_bots_catalog',
		provider: FirewallBotCatalogProvider::class,
		security: 'is_granted("ROLE_SITE_MANAGER")',
	),
])]
final class FirewallBotCatalogResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public string $id = '',
		public string $name = '',
		public string $category = '',
		/** @var list<string> */
		public array $userAgentPatterns = [],
		public string $verificationMethod = 'none',
		public string $description = '',
	) {
	}
}
