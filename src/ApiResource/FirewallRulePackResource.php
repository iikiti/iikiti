<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\FirewallRulePackStatusProvider;

#[ApiResource(operations: [
	new GetCollection(
		uriTemplate: '/admin/security/firewall/packs',
		name: 'admin_firewall_pack_status',
		provider: FirewallRulePackStatusProvider::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
])]
final class FirewallRulePackResource
{
	/** @param list<string> $versions */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public string $key,
		public string $name,
		public ?string $activeVersion,
		public ?string $checksum,
		public array $versions,
	) {
	}
}
