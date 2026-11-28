<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'digital_asset_variants')]
#[ORM\UniqueConstraint(name: 'uniq_digital_asset_variant', columns: ['asset_id', 'variant_key'])]
class DigitalAssetVariant
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: AbstractDigitalAsset::class)]
	#[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private AbstractDigitalAsset $asset;

	#[ORM\Column(name: 'variant_key', type: Types::STRING, length: 64)]
	private string $key;

	#[ORM\Column(name: 'storage_key', type: Types::STRING, length: 128)]
	private string $storageKey;

	#[ORM\Column(name: 'mime_type', type: Types::STRING, length: 127)]
	private string $mimeType;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	private ?int $width = null;

	#[ORM\Column(type: Types::INTEGER, nullable: true)]
	private ?int $height = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string $size;

	#[ORM\Column(name: 'checksum', type: Types::STRING, length: 64, nullable: true)]
	private ?string $checksum = null;

	#[ORM\Column(name: 'format', type: Types::STRING, length: 16, nullable: true)]
	private ?string $format = null;

	#[ORM\Column(name: 'quality', type: Types::SMALLINT, nullable: true)]
	private ?int $quality = null;

	#[ORM\Column(name: 'focal_crop', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $focalCrop = false;

	public function __construct(
		AbstractDigitalAsset $asset,
		string $key,
		string $storageKey,
		string $mimeType,
		int|string $size,
		?int $width = null,
		?int $height = null,
		?string $checksum = null,
		?string $format = null,
		?int $quality = null,
		bool $focalCrop = false,
	) {
		$this->asset = $asset;
		$this->key = $key;
		$this->storageKey = $storageKey;
		$this->mimeType = $mimeType;
		$this->size = $size;
		$this->width = $width;
		$this->height = $height;
		$this->checksum = $checksum;
		$this->setFormat($format);
		$this->setQuality($quality);
		$this->focalCrop = $focalCrop;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getAsset(): AbstractDigitalAsset
	{
		return $this->asset;
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getStorageKey(): string
	{
		return $this->storageKey;
	}

	public function setStorageKey(string $storageKey): static
	{
		$this->storageKey = $storageKey;

		return $this;
	}

	public function getMimeType(): string
	{
		return $this->mimeType;
	}

	public function setMimeType(string $mimeType): static
	{
		$this->mimeType = $mimeType;

		return $this;
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

	public function getSize(): int|string
	{
		return $this->size;
	}

	public function setSize(int|string $size): static
	{
		$this->size = $size;

		return $this;
	}

	public function getChecksum(): ?string
	{
		return $this->checksum;
	}

	public function setChecksum(?string $checksum): static
	{
		$this->checksum = $checksum;

		return $this;
	}

	public function getFormat(): ?string
	{
		return $this->format;
	}

	public function setFormat(?string $format): static
	{
		if (null === $format) {
			$this->format = null;

			return $this;
		}
		$normalized = strtolower(trim($format));
		if ('' === $normalized) {
			$this->format = null;

			return $this;
		}
		if (strlen($normalized) > 16 || 1 !== preg_match('/^[a-z0-9]{1,16}$/', $normalized)) {
			throw new \InvalidArgumentException('Variant format must be an alphanumeric identifier up to 16 characters.');
		}
		$this->format = $normalized;

		return $this;
	}

	public function getQuality(): ?int
	{
		return $this->quality;
	}

	public function setQuality(?int $quality): static
	{
		if (null !== $quality && ($quality < 1 || $quality > 100)) {
			throw new \InvalidArgumentException('Variant quality must be between 1 and 100.');
		}
		$this->quality = $quality;

		return $this;
	}

	public function isFocalCrop(): bool
	{
		return $this->focalCrop;
	}

	public function setFocalCrop(bool $focalCrop): static
	{
		$this->focalCrop = $focalCrop;

		return $this;
	}
}
