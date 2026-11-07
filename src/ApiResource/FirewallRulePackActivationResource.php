<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\State\Processor\FirewallRulePackActivationProcessor;

#[ApiResource(operations: [
	new Post(
		uriTemplate: '/admin/security/firewall/packs/{key}/activate',
		name: 'admin_firewall_pack_activate',
		processor: FirewallRulePackActivationProcessor::class,
		security: 'is_granted("ROLE_ADMIN")',
	),
])]
final class FirewallRulePackActivationResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public string $key = '',
		public string $version = '',
		public bool $success = false,
	) {
	}
}
