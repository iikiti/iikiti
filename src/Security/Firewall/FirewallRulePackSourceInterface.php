<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.security.firewall_rule_pack_source')]
interface FirewallRulePackSourceInterface
{
	public function getKey(): string;

	public function getName(): string;

	/** HTTPS URL for a signed JSON envelope. */
	public function getUrl(): string;

	/** Base64-encoded Ed25519 public key pinned by trusted PHP/plugin code. */
	public function getPublicKey(): string;
}
