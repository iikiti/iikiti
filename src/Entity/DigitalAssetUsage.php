<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\DigitalAssetUsageRepository;

#[ORM\Entity(repositoryClass: DigitalAssetUsageRepository::class)]
#[ORM\Table(name: 'digital_asset_usages')]
#[ORM\Index(name: 'idx_digital_asset_usages_asset', columns: ['asset_id'])]
#[ORM\Index(name: 'idx_digital_asset_usages_context', columns: ['context_type', 'context_id'])]
class DigitalAssetUsage
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(name: 'asset_id', type: Types::BIGINT)]
	private int|string $assetId;

	#[ORM\Column(name: 'context_type', type: Types::STRING, length: 32)]
	private string $contextType;

	#[ORM\Column(name: 'context_id', type: Types::BIGINT)]
	private int|string $contextId;

	#[ORM\Column(name: 'field_path', type: Types::STRING, length: 255)]
	private string $fieldPath;

	#[ORM\Column(name: 'is_draft', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isDraft = false;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['default' => 0])]
	private int|string $siteId = 0;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	public function __construct(
		int|string $assetId,
		string $contextType,
		int|string $contextId,
		string $fieldPath,
		bool $isDraft = false,
		int|string $siteId = 0,
		?\DateTimeImmutable $updatedAt = null,
	) {
		$this->assetId = $assetId;
		$this->contextType = strtolower(trim($contextType));
		$this->contextId = $contextId;
		$this->fieldPath = mb_substr(trim($fieldPath), 0, 255);
		$this->isDraft = $isDraft;
		$this->siteId = $siteId;
		$this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getAssetId(): int|string
	{
		return $this->assetId;
	}

	public function getContextType(): string
	{
		return $this->contextType;
	}

	public function getContextId(): int|string
	{
		return $this->contextId;
	}

	public function getFieldPath(): string
	{
		return $this->fieldPath;
	}

	public function isDraft(): bool
	{
		return $this->isDraft;
	}

	public function getSiteId(): int|string
	{
		return $this->siteId;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}
}
