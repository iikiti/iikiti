<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Value\LocalizedValue;

#[ORM\Entity(repositoryClass: DigitalAssetRepository::class)]
#[ORM\Table(name: 'object_lookup')]
abstract class AbstractDigitalAsset extends DbObject
{
	public const SITE_SPECIFIC = false;

	public const EXPIRY_ACTION_ALERT = 'alert';
	public const EXPIRY_ACTION_MAKE_PRIVATE = 'make_private';

	/** @var list<string> */
	public const ALLOWED_EXPIRY_ACTIONS = [
		self::EXPIRY_ACTION_ALERT,
		self::EXPIRY_ACTION_MAKE_PRIVATE,
	];

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

	#[ORM\Column(name: 'asset_current_version', type: Types::INTEGER, nullable: true)]
	private ?int $currentVersion = null;

	#[ORM\Column(name: 'asset_license_type', type: Types::STRING, length: 64, nullable: true)]
	private ?string $licenseType = null;

	#[ORM\Column(name: 'asset_license_holder', type: Types::STRING, length: 255, nullable: true)]
	private ?string $licenseHolder = null;

	#[ORM\Column(name: 'asset_license_url', type: Types::TEXT, nullable: true)]
	private ?string $licenseUrl = null;

	#[ORM\Column(name: 'asset_license_notes', type: Types::TEXT, nullable: true)]
	private ?string $licenseNotes = null;

	#[ORM\Column(name: 'asset_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $expiresAt = null;

	#[ORM\Column(name: 'asset_expiry_action', type: Types::STRING, length: 16, nullable: true)]
	private ?string $expiryAction = null;

	#[ORM\Column(name: 'asset_expiry_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $expiryNotifiedAt = null;

	#[ORM\Column(name: 'asset_expired_handled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $expiredHandledAt = null;

	/** @var Collection<int,Site> */
	#[ORM\ManyToMany(targetEntity: Site::class)]
	#[ORM\JoinTable(
		name: 'digital_asset_site_grants',
		joinColumns: [new ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
		inverseJoinColumns: [new ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
	)]
	private Collection $accessSites;

	#[ORM\ManyToOne(targetEntity: DigitalAssetFolder::class)]
	#[ORM\JoinColumn(name: 'asset_folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
	private ?DigitalAssetFolder $folder = null;

	/** @var Collection<int,DigitalAssetTag> */
	#[ORM\ManyToMany(targetEntity: DigitalAssetTag::class)]
	#[ORM\JoinTable(
		name: 'digital_asset_tags',
		joinColumns: [new ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
		inverseJoinColumns: [new ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
	)]
	private Collection $tags;

	/** @var Collection<int,DigitalAssetCollection> */
	#[ORM\ManyToMany(targetEntity: DigitalAssetCollection::class, mappedBy: 'assets')]
	private Collection $collections;

	public function __construct(string $title, string $mimeType, string $publicToken)
	{
		$this->title = trim($title);
		$this->mimeType = strtolower(trim($mimeType));
		$this->publicToken = $publicToken;
		$this->currentVersion = $this->isUploaded() ? 1 : null;
		$this->accessSites = new ArrayCollection();
		$this->tags = new ArrayCollection();
		$this->collections = new ArrayCollection();
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

	public function getCurrentVersion(): int
	{
		return $this->currentVersion ?? ($this->isUploaded() ? 1 : 0);
	}

	public function setCurrentVersion(?int $currentVersion): static
	{
		if (null !== $currentVersion && $currentVersion < 1) {
			throw new \InvalidArgumentException('Asset version number must be a positive integer.');
		}
		$this->currentVersion = $currentVersion;

		return $this;
	}

	public function getLicenseType(): ?string
	{
		return $this->licenseType;
	}

	public function setLicenseType(?string $licenseType): static
	{
		if (null === $licenseType) {
			$this->licenseType = null;

			return $this;
		}
		$trimmed = trim($licenseType);
		if ('' === $trimmed) {
			$this->licenseType = null;

			return $this;
		}
		if (mb_strlen($trimmed) > 64) {
			throw new \InvalidArgumentException('License type must not exceed 64 characters.');
		}
		$this->licenseType = $trimmed;

		return $this;
	}

	public function getLicenseHolder(): ?string
	{
		return $this->licenseHolder;
	}

	public function setLicenseHolder(?string $licenseHolder): static
	{
		if (null === $licenseHolder) {
			$this->licenseHolder = null;

			return $this;
		}
		$trimmed = trim($licenseHolder);
		if ('' === $trimmed) {
			$this->licenseHolder = null;

			return $this;
		}
		if (mb_strlen($trimmed) > 255) {
			throw new \InvalidArgumentException('License holder must not exceed 255 characters.');
		}
		$this->licenseHolder = $trimmed;

		return $this;
	}

	public function getLicenseUrl(): ?string
	{
		return $this->licenseUrl;
	}

	public function setLicenseUrl(?string $licenseUrl): static
	{
		if (null === $licenseUrl) {
			$this->licenseUrl = null;

			return $this;
		}
		$trimmed = trim($licenseUrl);
		if ('' === $trimmed) {
			$this->licenseUrl = null;

			return $this;
		}
		$parts = parse_url($trimmed);
		$scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
		if (
			strlen($trimmed) > 2048
			|| !is_array($parts)
			|| !in_array($scheme, ['http', 'https'], true)
			|| empty($parts['host'])
			|| false === filter_var($trimmed, FILTER_VALIDATE_URL)
		) {
			throw new \InvalidArgumentException('License URL must be a valid HTTP or HTTPS URL.');
		}
		$this->licenseUrl = $trimmed;

		return $this;
	}

	public function getLicenseNotes(): ?string
	{
		return $this->licenseNotes;
	}

	public function setLicenseNotes(?string $licenseNotes): static
	{
		if (null === $licenseNotes) {
			$this->licenseNotes = null;

			return $this;
		}
		$trimmed = trim($licenseNotes);
		$this->licenseNotes = '' === $trimmed ? null : $trimmed;

		return $this;
	}

	public function getExpiresAt(): ?\DateTimeImmutable
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
	{
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function getExpiryAction(): ?string
	{
		return $this->expiryAction;
	}

	public function setExpiryAction(?string $expiryAction): static
	{
		if (null === $expiryAction) {
			$this->expiryAction = null;

			return $this;
		}
		$normalized = strtolower(trim($expiryAction));
		if ('' === $normalized) {
			$this->expiryAction = null;

			return $this;
		}
		if (!in_array($normalized, self::ALLOWED_EXPIRY_ACTIONS, true)) {
			throw new \InvalidArgumentException('Expiry action must be "alert" or "make_private".');
		}
		$this->expiryAction = $normalized;

		return $this;
	}

	public function getExpiryNotifiedAt(): ?\DateTimeImmutable
	{
		return $this->expiryNotifiedAt;
	}

	public function setExpiryNotifiedAt(?\DateTimeImmutable $expiryNotifiedAt): static
	{
		$this->expiryNotifiedAt = $expiryNotifiedAt;

		return $this;
	}

	public function getExpiredHandledAt(): ?\DateTimeImmutable
	{
		return $this->expiredHandledAt;
	}

	public function setExpiredHandledAt(?\DateTimeImmutable $expiredHandledAt): static
	{
		$this->expiredHandledAt = $expiredHandledAt;

		return $this;
	}

	public function hasLicenseInfo(): bool
	{
		return null !== $this->licenseType
			|| null !== $this->licenseHolder
			|| null !== $this->licenseUrl
			|| null !== $this->licenseNotes
			|| null !== $this->expiresAt;
	}

	public function isExpired(?\DateTimeImmutable $now = null): bool
	{
		if (null === $this->expiresAt) {
			return false;
		}

		$clock = $now ?? new \DateTimeImmutable();

		return $this->expiresAt <= $clock;
	}

	public function isExpiringSoon(int $warningDays = 14, ?\DateTimeImmutable $now = null): bool
	{
		if (null === $this->expiresAt || $warningDays < 1) {
			return false;
		}

		$clock = $now ?? new \DateTimeImmutable();
		if ($this->expiresAt <= $clock) {
			return false;
		}

		return $this->expiresAt <= $clock->modify(sprintf('+%d days', $warningDays));
	}

	public function isEffectivelyPrivate(?\DateTimeImmutable $now = null): bool
	{
		if ('private' === $this->getVisibility()) {
			return true;
		}

		return $this->isExpired($now) && self::EXPIRY_ACTION_MAKE_PRIVATE === $this->expiryAction;
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

	public function getFolder(): ?DigitalAssetFolder
	{
		return $this->folder;
	}

	public function setFolder(?DigitalAssetFolder $folder): static
	{
		$this->folder = $folder;

		return $this;
	}

	/** @return Collection<int,DigitalAssetTag> */
	public function getTags(): Collection
	{
		if (!isset($this->tags)) {
			$this->tags = new ArrayCollection();
		}

		return $this->tags;
	}

	/** @param iterable<mixed> $tags */
	public function setTags(iterable $tags): static
	{
		$this->getTags()->clear();
		foreach ($tags as $tag) {
			if (!$tag instanceof DigitalAssetTag) {
				throw new \InvalidArgumentException('Asset tags must be DigitalAssetTag instances.');
			}
			if (!$this->hasTag($tag)) {
				$this->getTags()->add($tag);
			}
		}
		$this->syncTagsProperty();

		return $this;
	}

	public function addTag(DigitalAssetTag $tag): static
	{
		if (!$this->hasTag($tag)) {
			$this->getTags()->add($tag);
		}
		$this->syncTagsProperty();

		return $this;
	}

	public function removeTag(DigitalAssetTag $tag): static
	{
		$collection = $this->getTags();
		foreach ($collection as $key => $existing) {
			if ($this->isSameTag($existing, $tag)) {
				$collection->remove($key);
			}
		}
		$this->syncTagsProperty();

		return $this;
	}

	public function hasTag(DigitalAssetTag $tag): bool
	{
		foreach ($this->getTags() as $existing) {
			if ($this->isSameTag($existing, $tag)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Synchronizes normalized tag names into the `'tags'` ObjectProperty so
	 * PostgreSQLSearchEngine indexes asset tags consistently.
	 */
	public function syncTagsProperty(): void
	{
		$names = [];
		foreach ($this->getTags() as $tag) {
			$normalized = mb_strtolower(trim($tag->getName()));
			if ('' !== $normalized) {
				$names[$normalized] = true;
			}
		}

		if ([] === $names) {
			$this->getProperties()->remove('tags');

			return;
		}

		$sorted = array_keys($names);
		sort($sorted, SORT_STRING);
		$this->setProperty('tags', $sorted);
	}

	/** @return Collection<int,DigitalAssetCollection> */
	public function getCollections(): Collection
	{
		if (!isset($this->collections)) {
			$this->collections = new ArrayCollection();
		}

		return $this->collections;
	}

	private function isSameTag(DigitalAssetTag $a, DigitalAssetTag $b): bool
	{
		if ($a === $b) {
			return true;
		}
		if (null !== $a->getId() && null !== $b->getId()) {
			return (string) $a->getId() === (string) $b->getId();
		}

		return $a->getNamespace() === $b->getNamespace() && $a->getSlug() === $b->getSlug();
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
