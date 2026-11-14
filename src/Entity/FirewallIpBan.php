<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * Operational temporary IP/CIDR ban created by auto-ban escalation or an operator.
 *
 * Standalone by design (see docs/database-objects.md): high-volume, short-TTL state
 * with its own lifecycle, not editable CMS content. Site 0 denotes a global ban.
 */
#[ORM\Entity(repositoryClass: FirewallIpBanRepository::class)]
#[ORM\Table(name: 'firewall_ip_bans')]
#[ORM\Index(name: 'idx_firewall_ip_bans_active', columns: ['site_id', 'expires_at'])]
#[ORM\Index(name: 'idx_firewall_ip_bans_cidr', columns: ['site_id', 'cidr'])]
class FirewallIpBan
{
	private const MAX_REASON_MESSAGE_LENGTH = 512;

	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
	private int|string $siteId = 0;

	#[ORM\Column(type: Types::STRING, length: 64)]
	private string $cidr;

	#[ORM\Column(name: 'reason_code', type: Types::STRING, length: 64)]
	private string $reasonCode;

	#[ORM\Column(name: 'reason_message', type: Types::STRING, length: 512, options: ['default' => ''])]
	private string $reasonMessage = '';

	#[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
	private int $strikes = 1;

	#[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $expiresAt = null;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	#[ORM\Column(name: 'promoted_rule_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $promotedRuleId = null;

	public function __construct()
	{
		$now = new \DateTimeImmutable();
		$this->createdAt = $now;
		$this->updatedAt = $now;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getSiteId(): int|string
	{
		return $this->siteId;
	}

	public function setSiteId(int|string $siteId): void
	{
		$this->siteId = $siteId;
	}

	public function getCidr(): string
	{
		return $this->cidr;
	}

	/** @throws \InvalidArgumentException when the value is not a valid IP or CIDR */
	public function setCidr(string $cidr): void
	{
		$this->cidr = IpCidrNormalizer::normalize($cidr)['cidr'];
	}

	public function getReasonCode(): string
	{
		return $this->reasonCode;
	}

	public function setReasonCode(string $reasonCode): void
	{
		$this->reasonCode = $reasonCode;
	}

	public function getReasonMessage(): string
	{
		return $this->reasonMessage;
	}

	public function setReasonMessage(string $reasonMessage): void
	{
		if (strlen($reasonMessage) > self::MAX_REASON_MESSAGE_LENGTH) {
			throw new \InvalidArgumentException('Firewall ban reason message is too long.');
		}
		$this->reasonMessage = $reasonMessage;
	}

	public function getStrikes(): int
	{
		return $this->strikes;
	}

	public function setStrikes(int $strikes): void
	{
		$this->strikes = max(1, $strikes);
	}

	public function getExpiresAt(): ?\DateTimeImmutable
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
	{
		$this->expiresAt = $expiresAt;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}

	public function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getPromotedRuleId(): int|string|null
	{
		return $this->promotedRuleId;
	}

	public function setPromotedRuleId(int|string|null $promotedRuleId): void
	{
		$this->promotedRuleId = $promotedRuleId;
	}

	public function isActive(?\DateTimeImmutable $now = null): bool
	{
		return null === $this->expiresAt || $this->expiresAt > ($now ?? new \DateTimeImmutable());
	}
}
