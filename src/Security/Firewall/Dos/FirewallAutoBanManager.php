<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Dos;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Accumulates weighted strikes per client subnet and escalates to temporary bans with backoff.
 *
 * Strikes live in `cache.firewall_ratelimit` for `autoBanWindow` seconds. Bans are persisted in
 * `firewall_ip_bans` and the compiled IP matcher is invalidated so enforcement takes effect at once.
 */
final class FirewallAutoBanManager
{
	private const STRIKE_KEY_PREFIX = 'fw_strike_v1_';
	private const GLOBAL_SITE_ID = 0;
	private const ENFORCE = 'enforce';
	private const MAX_WEIGHT = 100;

	public function __construct(
		private readonly FirewallIpBanRepository $bans,
		private readonly EntityManagerInterface $entityManager,
		private readonly FirewallIpRuleStore $ruleStore,
		#[Autowire(service: 'cache.firewall_ratelimit')]
		private readonly CacheItemPoolInterface $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Adds weighted strikes for a client and creates an escalated ban once the threshold is reached.
	 *
	 * Returns the ban only when one was created or extended in this call; null otherwise.
	 *
	 * @param array<string,mixed> $dosConfig
	 */
	public function recordStrike(
		string $clientIp,
		string $reasonCode,
		string $reasonMessage,
		array $dosConfig,
		int|string|null $siteId,
		int $weight = 1,
	): ?FirewallIpBan {
		if (!($dosConfig['autoBanEnabled'] ?? false)) {
			return null;
		}
		// Ban the client's subnet rather than the single address so rotating hosts in one range are covered.
		$cidr = IpCidrNormalizer::subnetKey($clientIp);
		if (null === $cidr) {
			return null;
		}
		$strikes = $this->addStrikes($cidr, $siteId, $weight, $this->int($dosConfig, 'autoBanWindow', 300));
		if (null === $strikes || $strikes < $this->int($dosConfig, 'autoBanStrikeThreshold', 5)) {
			return null;
		}
		if (self::ENFORCE !== ($dosConfig['autoBanAction'] ?? 'report')) {
			$this->logger->info('Firewall auto-ban threshold reached in report mode.', ['reason' => $reasonCode, 'site_id' => $siteId]);

			return null;
		}

		$durations = $this->durations($dosConfig);
		$priorBans = $this->countPriorBans($cidr, $siteId);
		$index = min($priorBans, count($durations) - 1);
		$ttl = $durations[$index];

		return $this->ban($cidr, $reasonCode, $reasonMessage, $ttl, $siteId ?? self::GLOBAL_SITE_ID, $strikes);
	}

	/**
	 * Creates a ban, or extends an existing unexpired ban for the same CIDR and scope.
	 */
	public function ban(
		string $cidr,
		string $reasonCode,
		string $reasonMessage,
		?int $ttlSeconds,
		int|string $siteId = 0,
		int $strikes = 1,
	): FirewallIpBan {
		$normalized = IpCidrNormalizer::normalize($cidr)['cidr'];
		$expiresAt = null === $ttlSeconds ? null : (new \DateTimeImmutable())->modify(sprintf('+%d seconds', max(1, $ttlSeconds)));

		$existing = $this->bans->findActiveOneByCidr($siteId, $normalized);
		$ban = $existing ?? new FirewallIpBan();
		$ban->setSiteId($siteId);
		$ban->setCidr($normalized);
		$ban->setReasonCode($reasonCode);
		$ban->setReasonMessage($reasonMessage);
		$ban->setStrikes(null === $existing ? $strikes : max($existing->getStrikes(), $strikes));
		// Extending keeps the longer of the two windows; a permanent existing ban (null expiry) stays permanent.
		$ban->setExpiresAt(null === $existing ? $expiresAt : $this->laterOf($existing->getExpiresAt(), $expiresAt));
		$ban->touch();

		$this->entityManager->persist($ban);
		$this->entityManager->flush();
		$this->invalidateRules($siteId);

		return $ban;
	}

	public function unban(int|string $banId): bool
	{
		$ban = $this->bans->find($banId);
		if (!$ban instanceof FirewallIpBan) {
			return false;
		}
		$siteId = $ban->getSiteId();
		$this->entityManager->remove($ban);
		$this->entityManager->flush();
		$this->invalidateRules($siteId);

		return true;
	}

	/** Removes every ban for an IP or CIDR in one scope (or all scopes when $siteId is null). Returns the count. */
	public function unbanByIpOrCidr(string $cidrOrIp, int|string|null $siteId = null): int
	{
		$normalized = IpCidrNormalizer::normalize($cidrOrIp)['cidr'];
		$qb = $this->entityManager->createQueryBuilder()
			->select('b')
			->from(FirewallIpBan::class, 'b')
			->andWhere('b.cidr = :cidr')
			->setParameter('cidr', $normalized);
		if (null !== $siteId) {
			$qb->andWhere('b.siteId = :siteId')->setParameter('siteId', (string) $siteId);
		}

		$bans = $qb->getQuery()->getResult();
		foreach ($bans as $ban) {
			$this->entityManager->remove($ban);
		}
		$this->entityManager->flush();
		if ([] !== $bans) {
			$this->invalidateRules($siteId);
		}

		return count($bans);
	}

	/** Increments the strike counter for a subject and returns the total, or null when unavailable. */
	private function addStrikes(string $subject, int|string|null $siteId, int $weight, int $window): ?int
	{
		$key = self::STRIKE_KEY_PREFIX.hash('sha256', (string) ($siteId ?? 0)."\0".$subject);
		$add = max(1, min($weight, self::MAX_WEIGHT));
		try {
			$item = $this->cache->getItem($key);
			$total = ($item->isHit() && is_int($item->get()) ? (int) $item->get() : 0) + $add;
			$item->set($total);
			$item->expiresAfter($window);
			$this->cache->save($item);

			return $total;
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall strike counter unavailable.', ['exception' => $exception]);

			return null;
		}
	}

	/** Counts bans in this scope for the CIDR, including expired ones, to drive backoff. */
	private function countPriorBans(string $cidr, int|string|null $siteId): int
	{
		return (int) $this->entityManager->createQueryBuilder()
			->select('COUNT(b.id)')
			->from(FirewallIpBan::class, 'b')
			->andWhere('b.cidr = :cidr')
			->andWhere('b.siteId = :siteId')
			->setParameter('cidr', $cidr)
			->setParameter('siteId', (string) ($siteId ?? self::GLOBAL_SITE_ID))
			->getQuery()
			->getSingleScalarResult();
	}

	/**
	 * @param array<string,mixed> $dosConfig
	 *
	 * @return list<int>
	 */
	private function durations(array $dosConfig): array
	{
		$durations = array_values(array_filter(
			is_array($dosConfig['autoBanDurations'] ?? null) ? $dosConfig['autoBanDurations'] : [],
			static fn (mixed $value): bool => is_int($value) && $value > 0,
		));

		return [] === $durations ? [300] : $durations;
	}

	/** A null bound means "never expires", which dominates any finite expiry. */
	private function laterOf(?\DateTimeImmutable $current, ?\DateTimeImmutable $candidate): ?\DateTimeImmutable
	{
		if (null === $current || null === $candidate) {
			return null;
		}

		return $current > $candidate ? $current : $candidate;
	}

	private function invalidateRules(int|string|null $siteId): void
	{
		if (null === $siteId || (string) $siteId === (string) self::GLOBAL_SITE_ID) {
			$this->ruleStore->invalidateAll();

			return;
		}
		$this->ruleStore->invalidateSite($siteId);
	}

	/** @param array<string,mixed> $dosConfig */
	private function int(array $dosConfig, string $key, int $default): int
	{
		$value = $dosConfig[$key] ?? null;

		return is_int($value) && $value > 0 ? $value : $default;
	}
}
