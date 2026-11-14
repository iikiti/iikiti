<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies additional verified CIDR ranges for a bot source key (for example a plugin
 * shipping an official published range list). Merged with stored signed feeds.
 */
#[AutoconfigureTag('iikiti.security.firewall_bot_cidr_provider')]
interface FirewallBotCidrProviderInterface
{
	public function getSourceKey(): string;

	/** @return list<string> */
	public function getCidrs(): array;
}
