<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;

/**
 * Curated collection (lightbox / gallery / campaign bundle) of digital assets.
 *
 * Stored as a global DbObject (`SITE_SPECIFIC = false`, `site_id = 0`).
 */
#[ORM\Entity(repositoryClass: DigitalAssetCollectionRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[DbObjectDiscriminator('digitalassetcollection')]
class DigitalAssetCollection extends DbObject
{
	public const SITE_SPECIFIC = false;

	#[ORM\Column(name: 'collection_name', type: Types::STRING, length: 255, nullable: true)]
	private ?string $name = null;

	#[ORM\Column(name: 'collection_slug', type: Types::STRING, length: 255, nullable: true)]
	private ?string $slug = null;

	#[ORM\Column(name: 'collection_description', type: Types::TEXT, nullable: true)]
	private ?string $description = null;

	/** @var Collection<int,AbstractDigitalAsset> */
	#[ORM\ManyToMany(targetEntity: AbstractDigitalAsset::class, inversedBy: 'collections')]
	#[ORM\JoinTable(
		name: 'digital_asset_collection_items',
		joinColumns: [new ORM\JoinColumn(name: 'collection_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
		inverseJoinColumns: [new ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
	)]
	private Collection $assets;

	public function __construct(string $name, ?string $slug = null, ?string $description = null)
	{
		$this->assets = new ArrayCollection();
		$this->setProperties(new ArrayCollection());
		$this->setSiteId(0);
		$this->setCreatorId(0);
		$this->setCreatedDate(new \DateTimeImmutable());

		$this->setName($name);
		$this->setSlug(null !== $slug && '' !== trim($slug) ? $slug : $name);
		$this->setDescription($description);
	}

	public function getName(): string
	{
		return $this->name ?? throw new \LogicException('Collection name is missing.');
	}

	public function setName(string $name): static
	{
		$trimmed = trim($name);
		if ('' === $trimmed) {
			throw new \InvalidArgumentException('Collection name is required.');
		}
		if (mb_strlen($trimmed) > 255) {
			throw new \InvalidArgumentException('Collection name must not exceed 255 characters.');
		}
		$this->name = $trimmed;

		return $this;
	}

	public function getSlug(): string
	{
		return $this->slug ?? throw new \LogicException('Collection slug is missing.');
	}

	public function setSlug(string $slug): static
	{
		$this->slug = self::slugify($slug);

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): static
	{
		if (null === $description) {
			$this->description = null;

			return $this;
		}
		$trimmed = trim($description);
		$this->description = '' === $trimmed ? null : $trimmed;

		return $this;
	}

	/** @return Collection<int,AbstractDigitalAsset> */
	public function getAssets(): Collection
	{
		if (!isset($this->assets)) {
			$this->assets = new ArrayCollection();
		}

		return $this->assets;
	}

	public function hasAsset(AbstractDigitalAsset $asset): bool
	{
		foreach ($this->getAssets() as $existing) {
			if ($existing === $asset) {
				return true;
			}
			if (null !== $existing->getId() && null !== $asset->getId() && (string) $existing->getId() === (string) $asset->getId()) {
				return true;
			}
		}

		return false;
	}

	public function addAsset(AbstractDigitalAsset $asset): static
	{
		if (!$this->hasAsset($asset)) {
			$this->getAssets()->add($asset);
		}

		return $this;
	}

	public function removeAsset(AbstractDigitalAsset $asset): static
	{
		$collection = $this->getAssets();
		foreach ($collection as $key => $existing) {
			if ($existing === $asset || (null !== $existing->getId() && null !== $asset->getId() && (string) $existing->getId() === (string) $asset->getId())) {
				$collection->remove($key);
			}
		}

		return $this;
	}

	/** @param iterable<mixed> $assets */
	public function setAssets(iterable $assets): static
	{
		$this->getAssets()->clear();
		foreach ($assets as $asset) {
			if (!$asset instanceof AbstractDigitalAsset) {
				throw new \InvalidArgumentException('Collection items must be digital assets.');
			}
			$this->addAsset($asset);
		}

		return $this;
	}

	public static function slugify(string $value): string
	{
		$normalized = mb_strtolower(trim($value));
		$normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
		$normalized = trim($normalized, '-');
		if ('' === $normalized) {
			throw new \InvalidArgumentException('Collection slug must contain at least one alphanumeric character.');
		}

		return substr($normalized, 0, 255);
	}
}
