<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Repository\FirewallIpBanRepository;

/**
 * Feeds active operational bans into the compiled IP matcher as block rules.
 */
final readonly class FirewallIpBanProvider implements FirewallIpRuleProviderInterface
{
	private const BAN_PRIORITY = -1000;

	public function __construct(private FirewallIpBanRepository $bans)
	{
	}

	/** @return iterable<FirewallIpRuleSpec> */
	public function getRuleSpecs(int|string|null $siteId): iterable
	{
		foreach ($this->bans->findActiveForSiteAndGlobal($siteId) as $ban) {
			yield self::toSpec($ban);
		}
	}

	private static function toSpec(FirewallIpBan $ban): FirewallIpRuleSpec
	{
		$isGlobal = '0' === (string) $ban->getSiteId();

		return new FirewallIpRuleSpec(
			id: 'ip-ban:'.(string) $ban->getId(),
			cidr: $ban->getCidr(),
			action: 'block',
			inheritance: $isGlobal ? 'required' : 'default',
			scope: $isGlobal ? 'global' : 'site',
			priority: self::BAN_PRIORITY,
			expiresAt: $ban->getExpiresAt()?->getTimestamp(),
			reason: $ban->getReasonMessage(),
		);
	}
}
