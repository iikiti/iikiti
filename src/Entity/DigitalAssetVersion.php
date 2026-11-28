<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\DigitalAssetVersionRepository;

#[ORM\Entity(repositoryClass: DigitalAssetVersionRepository::class)]
#[ORM\Table(name: 'digital_asset_versions')]
#[ORM\UniqueConstraint(name: 'uniq_digital_asset_version', columns: ['asset_id', 'version_number'])]
#[ORM\Index(name: 'idx_digital_asset_versions_storage_key', columns: ['storage_key'])]
class DigitalAssetVersion
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: AbstractDigitalAsset::class)]
	#[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private AbstractDigitalAsset $asset;

	#[ORM\Column(name: 'version_number', type: Types::INTEGER)]
	private int $versionNumber;

	#[ORM\Column(name: 'storage_key', type: Types::STRING, length: 128)]
	private string $storageKey;

	#[ORM\Column(name: 'original_name', type: Types::STRING, length: 255)]
	private string $originalName;

	#[ORM\Column(name: 'mime_type', type: Types::STRING, length: 127)]
	private string $mimeType;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string $size;

	#[ORM\Column(type: Types::STRING, length: 64)]
	private string $checksum;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	private ?int $width = null;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	private ?int $height = null;

	#[ORM\Column(name: 'duration_ms', type: Types::INTEGER, nullable: true)]
	private ?int $durationMs = null;

	#[ORM\Column(name: 'change_note', type: Types::TEXT, nullable: true)]
	private ?string $changeNote = null;

	#[ORM\Column(name: 'created_by_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $createdById = null;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	public function __construct(
		AbstractDigitalAsset $asset,
		int $versionNumber,
		string $storageKey,
		string $originalName,
		string $mimeType,
		int|string $size,
		string $checksum,
		?int $width = null,
		?int $height = null,
		?int $durationMs = null,
		?string $changeNote = null,
		int|string|null $createdById = null,
		?\DateTimeImmutable $createdAt = null,
	) {
		if ($versionNumber < 1) {
			throw new \InvalidArgumentException('Version number must be a positive integer.');
		}
		$this->asset = $asset;
		$this->versionNumber = $versionNumber;
		$this->storageKey = $storageKey;
		$this->originalName = mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
		$this->mimeType = strtolower(trim($mimeType));
		$this->size = $size;
		$this->checksum = strtolower(trim($checksum));
		$this->width = $width;
		$this->height = $height;
		$this->durationMs = $durationMs;
		$this->changeNote = null !== $changeNote && '' !== trim($changeNote) ? trim($changeNote) : null;
		$this->createdById = self::normalizeCreatedById($createdById);
		$this->createdAt = $createdAt ?? new \DateTimeImmutable();
	}

	public static function normalizeCreatedById(int|string|null $createdById): int|string|null
	{
		if (null === $createdById) {
			return null;
		}
		if (is_int($createdById)) {
			return $createdById > 0 ? $createdById : null;
		}
		$trimmed = trim($createdById);
		if ('' === $trimmed || !ctype_digit($trimmed) || (int) $trimmed <= 0) {
			return null;
		}

		return $trimmed;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getAsset(): AbstractDigitalAsset
	{
		return $this->asset;
	}

	public function getVersionNumber(): int
	{
		return $this->versionNumber;
	}

	public function getStorageKey(): string
	{
		return $this->storageKey;
	}

	public function getOriginalName(): string
	{
		return $this->originalName;
	}

	public function getMimeType(): string
	{
		return $this->mimeType;
	}

	public function getSize(): int|string
	{
		return $this->size;
	}

	public function getChecksum(): string
	{
		return $this->checksum;
	}

	public function getWidth(): ?int
	{
		return $this->width;
	}

	public function setWidth(?int $width): static
	{
		$this->width = $width;

		return $this;
	}

	public function getHeight(): ?int
	{
		return $this->height;
	}

	public function setHeight(?int $height): static
	{
		$this->height = $height;

		return $this;
	}

	public function getDurationMs(): ?int
	{
		return $this->durationMs;
	}

	public function setDurationMs(?int $durationMs): static
	{
		$this->durationMs = $durationMs;

		return $this;
	}

	public function getChangeNote(): ?string
	{
		return $this->changeNote;
	}

	public function getCreatedById(): int|string|null
	{
		return $this->createdById;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}
}
