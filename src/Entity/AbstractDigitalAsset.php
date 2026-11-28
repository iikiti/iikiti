<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Value\LocalizedValue;

#[ORM\Entity(repositoryClass: DigitalAssetRepository::class)]
#[ORM\Table(name: 'object_lookup')]
abstract class AbstractDigitalAsset extends DbObject
{
	public const SITE_SPECIFIC = false;

	#[ORM\Column(name: 'asset_title', type: Types::STRING, length: 255, nullable: true)]
	private ?string $title = null;

	#[ORM\Column(name: 'asset_mime_type', type: Types::STRING, length: 127, nullable: true)]
	private ?string $mimeType = null;

	#[ORM\Column(name: 'asset_original_name', type: Types::STRING, length: 255, nullable: true)]
	private ?string $originalName = null;

	#[ORM\Column(name: 'asset_storage_key', type: Types::STRING, length: 128, nullable: true)]
	private ?string $storageKey = null;

	#[ORM\Column(name: 'asset_external_uri', type: Types::TEXT, nullable: true)]
	private ?string $externalUri = null;

	#[ORM\Column(name: 'asset_size', type: Types::BIGINT, nullable: true)]
	private int|string|null $size = null;

	#[ORM\Column(name: 'asset_checksum', type: Types::STRING, length: 64, nullable: true)]
	private ?string $checksum = null;

	#[ORM\Column(name: 'asset_public_token', type: Types::STRING, length: 64, nullable: true)]
	private ?string $publicToken = null;

	#[ORM\Column(name: 'asset_visibility', type: Types::STRING, length: 16, nullable: true, options: ['default' => 'private'])]
	private ?string $visibility = 'private';

	#[ORM\Column(name: 'asset_processing_status', type: Types::STRING, length: 16, nullable: true, options: ['default' => 'pending'])]
	private ?string $processingStatus = 'pending';

	#[ORM\Column(name: 'asset_width', type: Types::INTEGER, nullable: true)]
	private ?int $width = null;

	#[ORM\Column(name: 'asset_height', type: Types::INTEGER, nullable: true)]
	private ?int $height = null;

	#[ORM\Column(name: 'asset_focal_x', type: Types::FLOAT, nullable: true)]
	private ?float $focalX = null;

	#[ORM\Column(name: 'asset_focal_y', type: Types::FLOAT, nullable: true)]
	private ?float $focalY = null;

	#[ORM\Column(name: 'asset_dominant_color', type: Types::STRING, length: 9, nullable: true)]
	private ?string $dominantColor = null;

	#[ORM\Column(name: 'asset_blurhash', type: Types::STRING, length: 64, nullable: true)]
	private ?string $blurhash = null;

	#[ORM\Column(name: 'asset_duration_ms', type: Types::INTEGER, nullable: true)]
	private ?int $durationMs = null;

	/** @var array<string,mixed>|null */
	#[ORM\Column(name: 'asset_media_metadata', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
	private ?array $mediaMetadata = null;

	/** @var Collection<int,Site> */
	#[ORM\ManyToMany(targetEntity: Site::class)]
	#[ORM\JoinTable(
		name: 'digital_asset_site_grants',
		joinColumns: [new ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
		inverseJoinColumns: [new ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
	)]
	private Collection $accessSites;

	public function __construct(string $title, string $mimeType, string $publicToken)
	{
		$this->title = trim($title);
		$this->mimeType = strtolower(trim($mimeType));
		$this->publicToken = $publicToken;
		$this->accessSites = new ArrayCollection();
		$this->setProperties(new ArrayCollection());
	}

	public function getTitle(): string
	{
		return $this->title ?? throw new \LogicException('Asset title is missing.');
	}

	public function setTitle(string $title): static
	{
		$this->title = trim($title);

		return $this;
	}

	public function getMimeType(): string
	{
		return $this->mimeType ?? throw new \LogicException('Asset MIME type is missing.');
	}

	public function setMimeType(string $mimeType): static
	{
		$this->mimeType = strtolower(trim($mimeType));

		return $this;
	}

	public function getOriginalName(): ?string
	{
		return $this->originalName;
	}

	public function setOriginalName(?string $originalName): static
	{
		$this->originalName = null === $originalName ? null : mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255);

		return $this;
	}

	public function getStorageKey(): ?string
	{
		return $this->storageKey;
	}

	public function setStorageKey(?string $storageKey): static
	{
		$this->storageKey = $storageKey;

		return $this;
	}

	public function getExternalUri(): ?string
	{
		return $this->externalUri;
	}

	public function setExternalUri(?string $externalUri): static
	{
		$this->externalUri = $externalUri;

		return $this;
	}

	public function getSize(): int|string|null
	{
		return $this->size;
	}

	public function setSize(int|string|null $size): static
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

	public function getPublicToken(): string
	{
		return $this->publicToken ?? throw new \LogicException('Asset token is missing.');
	}

	public function getVisibility(): string
	{
		return $this->visibility ?? throw new \LogicException('Asset visibility is missing.');
	}

	public function setVisibility(string $visibility): static
	{
		if (!in_array($visibility, ['private', 'public'], true)) {
			throw new \InvalidArgumentException('Visibility must be private or public.');
		}
		$this->visibility = $visibility;

		return $this;
	}

	public function getProcessingStatus(): string
	{
		return $this->processingStatus ?? throw new \LogicException('Asset processing status is missing.');
	}

	public function setProcessingStatus(string $processingStatus): static
	{
		if (!in_array($processingStatus, ['pending', 'processing', 'complete', 'failed'], true)) {
			throw new \InvalidArgumentException('Invalid asset processing status.');
		}
		$this->processingStatus = $processingStatus;

		return $this;
	}

	public function getWidth(): ?int
	{
		return $this->width;
	}

	public function setWidth(?int $width): static
	{
		if (null !== $width && $width < 1) {
			throw new \InvalidArgumentException('Asset width must be a positive integer.');
		}
		$this->width = $width;

		return $this;
	}

	public function getHeight(): ?int
	{
		return $this->height;
	}

	public function setHeight(?int $height): static
	{
		if (null !== $height && $height < 1) {
			throw new \InvalidArgumentException('Asset height must be a positive integer.');
		}
		$this->height = $height;

		return $this;
	}

	public function getFocalX(): ?float
	{
		return $this->focalX;
	}

	public function getEffectiveFocalX(): float
	{
		return $this->focalX ?? 0.5;
	}

	public function setFocalX(?float $focalX): static
	{
		if (null !== $focalX && (!is_finite($focalX) || $focalX < 0.0 || $focalX > 1.0)) {
			throw new \InvalidArgumentException('Focal point X must be between 0.0 and 1.0.');
		}
		$this->focalX = $focalX;

		return $this;
	}

	public function getFocalY(): ?float
	{
		return $this->focalY;
	}

	public function getEffectiveFocalY(): float
	{
		return $this->focalY ?? 0.5;
	}

	public function setFocalY(?float $focalY): static
	{
		if (null !== $focalY && (!is_finite($focalY) || $focalY < 0.0 || $focalY > 1.0)) {
			throw new \InvalidArgumentException('Focal point Y must be between 0.0 and 1.0.');
		}
		$this->focalY = $focalY;

		return $this;
	}

	public function setFocalPoint(?float $focalX, ?float $focalY): static
	{
		$this->setFocalX($focalX);
		$this->setFocalY($focalY);

		return $this;
	}

	public function getDominantColor(): ?string
	{
		return $this->dominantColor;
	}

	public function setDominantColor(?string $dominantColor): static
	{
		if (null === $dominantColor) {
			$this->dominantColor = null;

			return $this;
		}
		$normalized = strtolower(trim($dominantColor));
		if (1 !== preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $normalized)) {
			throw new \InvalidArgumentException('Dominant color must be a valid hex color (#rrggbb or #rrggbbaa).');
		}
		$this->dominantColor = $normalized;

		return $this;
	}

	public function getBlurhash(): ?string
	{
		return $this->blurhash;
	}

	public function setBlurhash(?string $blurhash): static
	{
		if (null === $blurhash) {
			$this->blurhash = null;

			return $this;
		}
		$trimmed = trim($blurhash);
		if ('' === $trimmed) {
			$this->blurhash = null;

			return $this;
		}
		if (strlen($trimmed) > 64) {
			throw new \InvalidArgumentException('BlurHash must not exceed 64 characters.');
		}
		$this->blurhash = $trimmed;

		return $this;
	}

	public function getDurationMs(): ?int
	{
		return $this->durationMs;
	}

	public function setDurationMs(?int $durationMs): static
	{
		if (null !== $durationMs && $durationMs < 0) {
			throw new \InvalidArgumentException('Asset duration must be non-negative.');
		}
		$this->durationMs = $durationMs;

		return $this;
	}

	/** @return array<string,mixed>|null */
	public function getMediaMetadata(): ?array
	{
		return $this->mediaMetadata;
	}

	/** @param array<string,mixed>|null $mediaMetadata */
	public function setMediaMetadata(?array $mediaMetadata): static
	{
		$this->mediaMetadata = $mediaMetadata;

		return $this;
	}

	/** @return Collection<int,Site> */
	public function getAccessSites(): Collection
	{
		return $this->accessSites;
	}

	/** @param iterable<mixed> $sites */
	public function setAccessSites(iterable $sites): static
	{
		$this->accessSites->clear();
		foreach ($sites as $site) {
			if (!$site instanceof Site) {
				throw new \InvalidArgumentException('Asset access grants must reference sites.');
			}
			$this->accessSites->add($site);
		}

		return $this;
	}

	public function grantsAccessTo(Site $site): bool
	{
		foreach ($this->accessSites as $grantedSite) {
			if ((string) $grantedSite->getId() === (string) $site->getId()) {
				return true;
			}
		}

		return false;
	}

	public function setExtractedText(string $text): static
	{
		$this->setProperty('extracted_text', $text);

		return $this;
	}

	public function getExtractedText(): string
	{
		$value = $this->getProperties()->get('extracted_text')?->getValue();

		return is_string($value) ? $value : '';
	}

	public function getDefaultAlt(): LocalizedValue|string|null
	{
		return $this->getLocalizedTextProperty('default_alt');
	}

	public function setDefaultAlt(LocalizedValue|string|null $defaultAlt): static
	{
		return $this->setLocalizedTextProperty('default_alt', $defaultAlt);
	}

	public function getDefaultCaption(): LocalizedValue|string|null
	{
		return $this->getLocalizedTextProperty('default_caption');
	}

	public function setDefaultCaption(LocalizedValue|string|null $defaultCaption): static
	{
		return $this->setLocalizedTextProperty('default_caption', $defaultCaption);
	}

	public function getDescription(): LocalizedValue|string|null
	{
		return $this->getLocalizedTextProperty('description');
	}

	public function setDescription(LocalizedValue|string|null $description): static
	{
		return $this->setLocalizedTextProperty('description', $description);
	}

	abstract public function isUploaded(): bool;

	private function getLocalizedTextProperty(string $name): LocalizedValue|string|null
	{
		$property = $this->getProperties()->get($name);
		if (!$property instanceof ObjectProperty) {
			return null;
		}
		$localized = $property->getLocalizedValue();
		if (null !== $localized) {
			return $localized;
		}
		$value = $property->getValue();

		return is_string($value) ? $value : null;
	}

	private function setLocalizedTextProperty(string $name, LocalizedValue|string|null $value): static
	{
		if (null === $value) {
			$this->getProperties()->remove($name);

			return $this;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' === $trimmed) {
				$this->getProperties()->remove($name);

				return $this;
			}
			$this->setProperty($name, $trimmed);

			return $this;
		}
		$this->setProperty($name, $value);

		return $this;
	}
}
