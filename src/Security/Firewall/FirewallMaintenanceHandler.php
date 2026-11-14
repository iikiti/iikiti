<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;

/**
 * Deletes expired operational bans and expired managed IP rules, then refreshes compiled matchers.
 */
final readonly class FirewallMaintenanceHandler implements BatchMessageHandlerInterface
{
	public const TYPE = 'security.firewall.maintenance';

	public function __construct(
		private FirewallIpBanRepository $bans,
		private FirewallIpRuleRepository $ipRules,
		private EntityManagerInterface $entityManager,
		private FirewallIpRuleStore $ruleStore,
	) {
	}

	public function getType(): string
	{
		return self::TYPE;
	}

	public function handle(BatchMessage $message, BatchMessageContext $context): void
	{
		$now = new \DateTimeImmutable();
		$deletedBans = $this->bans->deleteExpired($now);
		$deletedRules = $this->purgeExpiredRules($now);
		if ($deletedRules > 0) {
			$this->ruleStore->invalidateAll();
		}
		$context->report('Expired firewall entries purged.', ['bans' => $deletedBans, 'ip_rules' => $deletedRules]);
	}

	/** Expiry is an object property, so expired rules are identified in PHP rather than in SQL. */
	private function purgeExpiredRules(\DateTimeImmutable $now): int
	{
		$deleted = 0;
		foreach ($this->ipRules->findBy([], options: ['filterBySite' => false]) as $rule) {
			if (!$this->isExpired($rule, $now)) {
				continue;
			}
			$this->entityManager->remove($rule);
			++$deleted;
		}
		$this->entityManager->flush();

		return $deleted;
	}

	private function isExpired(FirewallIpRule $rule, \DateTimeImmutable $now): bool
	{
		$expiresAt = $rule->getExpiresAt();

		return null !== $expiresAt && $expiresAt <= $now;
	}
}
