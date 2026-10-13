<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\DigitalAssetRepository;

#[ORM\Entity(repositoryClass: DigitalAssetRepository::class)]
#[ORM\Table(name: 'objects')]
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

	#[ORM\Column(name: 'asset_public_token', type: Types::STRING, length: 64, nullable: true, unique: true)]
	private ?string $publicToken = null;

	#[ORM\Column(name: 'asset_visibility', type: Types::STRING, length: 16, nullable: true, options: ['default' => 'private'])]
	private ?string $visibility = 'private';

	#[ORM\Column(name: 'asset_processing_status', type: Types::STRING, length: 16, nullable: true, options: ['default' => 'pending'])]
	private ?string $processingStatus = 'pending';

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

	abstract public function isUploaded(): bool;
}
