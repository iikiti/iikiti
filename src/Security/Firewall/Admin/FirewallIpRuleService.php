<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;

/**
 * Creates, updates, deletes, and bulk-imports managed IP/CIDR rules for one site scope.
 *
 * This is the single write path for the API processors; it mirrors the persistence used by the
 * `iikiti:firewall:*` console commands and always invalidates the compiled IP matcher.
 */
final readonly class FirewallIpRuleService
{
	public const GLOBAL_SITE_ID = 0;
	public const MAX_IMPORT_ITEMS = 500;

	public function __construct(
		private EntityManagerInterface $entityManager,
		private FirewallIpRuleRepository $rules,
		private FirewallIpRuleStore $ruleStore,
	) {
	}

	/** @return list<FirewallIpRule> */
	public function listForScope(int|string $siteId): array
	{
		return $this->rules->findForScope($siteId);
	}

	public function find(int|string $siteId, int|string $id): ?FirewallIpRule
	{
		$rule = $this->rules->find($id);
		if (!$rule instanceof FirewallIpRule || (string) $rule->getSiteId() !== (string) $siteId) {
			return null;
		}

		return $rule;
	}

	/** @param array<string,mixed> $attributes */
	public function create(int|string $siteId, array $attributes): FirewallIpRule
	{
		$rule = new FirewallIpRule();
		$this->apply($rule, $attributes);
		$rule->setSiteId($siteId);
		$rule->setCreatedDate(new \DateTimeImmutable());
		$rule->setCreatorId(0);
		$this->persist($rule, $siteId);

		return $rule;
	}

	/** @param array<string,mixed> $attributes */
	public function update(FirewallIpRule $rule, array $attributes): FirewallIpRule
	{
		$this->apply($rule, $attributes);
		$this->persist($rule, $rule->getSiteId());

		return $rule;
	}

	public function delete(FirewallIpRule $rule): void
	{
		$siteId = $rule->getSiteId();
		$this->entityManager->remove($rule);
		$this->entityManager->flush();
		$this->ruleStore->invalidateSite($siteId);
	}

	/**
	 * Imports up to {@see self::MAX_IMPORT_ITEMS} rules atomically: either every item is stored or none.
	 *
	 * @param list<array<string,mixed>> $items
	 *
	 * @return list<FirewallIpRule>
	 */
	public function importAtomically(int|string $siteId, array $items): array
	{
		if (count($items) > self::MAX_IMPORT_ITEMS) {
			throw new \InvalidArgumentException(sprintf('At most %d rules can be imported at once.', self::MAX_IMPORT_ITEMS));
		}

		$created = [];
		$this->entityManager->beginTransaction();
		try {
			foreach ($items as $item) {
				$rule = new FirewallIpRule();
				$this->apply($rule, $item);
				$rule->setSiteId($siteId);
				$rule->setCreatedDate(new \DateTimeImmutable());
				$rule->setCreatorId(0);
				$this->entityManager->persist($rule);
				$created[] = $rule;
			}
			$this->entityManager->flush();
			$this->entityManager->commit();
		} catch (\Throwable $exception) {
			$this->entityManager->rollback();
			throw $exception;
		}

		$this->ruleStore->invalidateSite($siteId);

		return $created;
	}

	/** @param array<string,mixed> $attributes */
	private function apply(FirewallIpRule $rule, array $attributes): void
	{
		if (array_key_exists('cidr', $attributes)) {
			$rule->setCidr((string) $attributes['cidr']);
		}
		if (array_key_exists('action', $attributes)) {
			$rule->setAction((string) $attributes['action']);
		}
		if (array_key_exists('inheritance', $attributes)) {
			$rule->setInheritance((string) $attributes['inheritance']);
		}
		if (array_key_exists('reason', $attributes)) {
			$rule->setReason((string) $attributes['reason']);
		}
		if (array_key_exists('enabled', $attributes)) {
			$rule->setEnabled((bool) $attributes['enabled']);
		}
		if (array_key_exists('priority', $attributes)) {
			$rule->setPriority((int) $attributes['priority']);
		}
		if (array_key_exists('expiresAt', $attributes)) {
			$rule->setExpiresAt($this->parseExpiry($attributes['expiresAt']));
		}
	}

	private function parseExpiry(mixed $value): ?\DateTimeImmutable
	{
		if (null === $value || '' === $value) {
			return null;
		}
		if (!is_string($value)) {
			throw new \InvalidArgumentException('expiresAt must be an ISO-8601 date-time string or null.');
		}
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			throw new \InvalidArgumentException('expiresAt must be an ISO-8601 date-time string or null.');
		}
	}

	private function persist(FirewallIpRule $rule, int|string $siteId): void
	{
		$this->entityManager->persist($rule);
		$this->entityManager->flush();
		$this->ruleStore->invalidateSite($siteId);
	}
}
