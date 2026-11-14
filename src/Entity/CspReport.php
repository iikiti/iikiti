<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\CspReportRepository;

/**
 * CSP violation report.
 *
 * Stores violation reports posted by browsers to /api/internal/csp-reports so
 * administrators can review them before enforcing a Content-Security-Policy.
 */
#[ORM\Entity(repositoryClass: CspReportRepository::class)]
#[ORM\Table(name: 'csp_reports')]
#[ORM\Index(name: 'idx_csp_reports_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_csp_reports_site', columns: ['site_id'])]
#[ORM\Index(name: 'idx_csp_reports_directive', columns: ['violated_directive'])]
final class CspReport
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
	private int|string $siteId = 0;

	#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
	private ?string $userAgent = null;

	#[ORM\Column(name: 'client_ip', type: Types::STRING, length: 64, nullable: true)]
	private ?string $clientIp = null;

	#[ORM\Column(name: 'document_uri', type: Types::STRING, length: 2048, nullable: true)]
	private ?string $documentUri = null;

	#[ORM\Column(name: 'referrer', type: Types::STRING, length: 2048, nullable: true)]
	private ?string $referrer = null;

	#[ORM\Column(name: 'blocked_uri', type: Types::STRING, length: 2048, nullable: true)]
	private ?string $blockedUri = null;

	#[ORM\Column(name: 'violated_directive', type: Types::STRING, length: 255, nullable: true)]
	private ?string $violatedDirective = null;

	#[ORM\Column(name: 'effective_directive', type: Types::STRING, length: 255, nullable: true)]
	private ?string $effectiveDirective = null;

	#[ORM\Column(name: 'original_policy', type: Types::STRING, length: 1024, nullable: true)]
	private ?string $originalPolicy = null;

	#[ORM\Column(name: 'status_code', type: Types::SMALLINT, nullable: true)]
	private ?int $statusCode = null;

	/**
	 * @var array<string,mixed>
	 */
	#[ORM\Column(type: Types::JSON)]
	private array $reportBody = [];

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	public function __construct()
	{
		$this->createdAt = new \DateTimeImmutable();
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

	public function getUserAgent(): ?string
	{
		return $this->userAgent;
	}

	public function setUserAgent(?string $userAgent): void
	{
		$this->userAgent = $userAgent;
	}

	public function getClientIp(): ?string
	{
		return $this->clientIp;
	}

	public function setClientIp(?string $clientIp): void
	{
		$this->clientIp = $clientIp;
	}

	public function getDocumentUri(): ?string
	{
		return $this->documentUri;
	}

	public function setDocumentUri(?string $documentUri): void
	{
		$this->documentUri = $documentUri;
	}

	public function getReferrer(): ?string
	{
		return $this->referrer;
	}

	public function setReferrer(?string $referrer): void
	{
		$this->referrer = $referrer;
	}

	public function getBlockedUri(): ?string
	{
		return $this->blockedUri;
	}

	public function setBlockedUri(?string $blockedUri): void
	{
		$this->blockedUri = $blockedUri;
	}

	public function getViolatedDirective(): ?string
	{
		return $this->violatedDirective;
	}

	public function setViolatedDirective(?string $violatedDirective): void
	{
		$this->violatedDirective = $violatedDirective;
	}

	public function getEffectiveDirective(): ?string
	{
		return $this->effectiveDirective;
	}

	public function setEffectiveDirective(?string $effectiveDirective): void
	{
		$this->effectiveDirective = $effectiveDirective;
	}

	public function getOriginalPolicy(): ?string
	{
		return $this->originalPolicy;
	}

	public function setOriginalPolicy(?string $originalPolicy): void
	{
		$this->originalPolicy = $originalPolicy;
	}

	public function getStatusCode(): ?int
	{
		return $this->statusCode;
	}

	public function setStatusCode(?int $statusCode): void
	{
		$this->statusCode = $statusCode;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getReportBody(): array
	{
		return $this->reportBody;
	}

	/**
	 * @param array<string,mixed> $reportBody
	 */
	public function setReportBody(array $reportBody): void
	{
		$this->reportBody = $reportBody;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTimeImmutable $createdAt): void
	{
		$this->createdAt = $createdAt;
	}
}
