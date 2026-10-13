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

	public function __construct(AbstractDigitalAsset $asset, string $key, string $storageKey, string $mimeType, int|string $size, ?int $width = null, ?int $height = null)
	{
		$this->asset = $asset;
		$this->key = $key;
		$this->storageKey = $storageKey;
		$this->mimeType = $mimeType;
		$this->size = $size;
		$this->width = $width;
		$this->height = $height;
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

	public function getMimeType(): string
	{
		return $this->mimeType;
	}

	public function getWidth(): ?int
	{
		return $this->width;
	}

	public function getHeight(): ?int
	{
		return $this->height;
	}

	public function getSize(): int|string
	{
		return $this->size;
	}
}
