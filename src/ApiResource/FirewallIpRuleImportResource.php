<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\ApiResource\Output\FirewallIpRuleImportOutput;
use iikiti\CMS\State\Processor\FirewallIpRuleImportProcessor;

/**
 * Atomic bulk import of up to 500 global IP/CIDR rules.
 */
#[ApiResource(
	formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
	output: FirewallIpRuleImportOutput::class,
	operations: [
	new Post(
		uriTemplate: '/admin/security/firewall/ip-rules/import',
		name: 'admin_firewall_ip_rules_import',
		processor: FirewallIpRuleImportProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
])]
final class FirewallIpRuleImportResource
{
	/** @param list<array<string,mixed>> $items */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?string $id = null,
		public array $items = [],
		public int $importedCount = 0,
		/** @var list<FirewallIpRuleResource> */
		public array $rules = [],
	) {
	}
}
