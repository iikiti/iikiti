<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;

/**
 * Hierarchical folder for organizing digital assets in the DAM.
 *
 * Stored as a global DbObject (`SITE_SPECIFIC = false`, `site_id = 0`) with
 * materialized path (`/parent/slug`) and optional per-site access grants.
 * When `accessSites` is empty, the folder is globally accessible to all sites
 * and manageable only by global administrators.
 */
#[ORM\Entity(repositoryClass: DigitalAssetFolderRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[DbObjectDiscriminator('digitalassetfolder')]
class DigitalAssetFolder extends DbObject
{
	public const SITE_SPECIFIC = false;

	#[ORM\Column(name: 'folder_name', type: Types::STRING, length: 255, nullable: true)]
	private ?string $name = null;

	#[ORM\Column(name: 'folder_slug', type: Types::STRING, length: 255, nullable: true)]
	private ?string $slug = null;

	#[ORM\Column(name: 'folder_path', type: Types::TEXT, nullable: true)]
	private ?string $path = null;

	#[ORM\ManyToOne(targetEntity: self::class)]
	#[ORM\JoinColumn(name: 'folder_parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
	private ?self $parent = null;

	/** @var Collection<int,Site> */
	#[ORM\ManyToMany(targetEntity: Site::class)]
	#[ORM\JoinTable(
		name: 'digital_asset_folder_site_grants',
		joinColumns: [new ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
		inverseJoinColumns: [new ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
	)]
	private Collection $accessSites;

	public function __construct(string $name, ?self $parent = null, ?string $slug = null)
	{
		$this->accessSites = new ArrayCollection();
		$this->setProperties(new ArrayCollection());
		$this->setSiteId(0);
		$this->setCreatorId(0);
		$this->setCreatedDate(new \DateTimeImmutable());

		$this->setName($name);
		$this->slug = self::slugify(null !== $slug && '' !== trim($slug) ? $slug : $name);
		$this->setParent($parent);
	}

	public function getName(): string
	{
		return $this->name ?? throw new \LogicException('Folder name is missing.');
	}

	public function setName(string $name): static
	{
		$trimmed = trim($name);
		if ('' === $trimmed) {
			throw new \InvalidArgumentException('Folder name is required.');
		}
		if (mb_strlen($trimmed) > 255) {
			throw new \InvalidArgumentException('Folder name must not exceed 255 characters.');
		}
		$this->name = $trimmed;

		return $this;
	}

	public function getSlug(): string
	{
		return $this->slug ?? throw new \LogicException('Folder slug is missing.');
	}

	public function setSlug(string $slug): static
	{
		$this->slug = self::slugify($slug);
		$this->path = $this->computePath();

		return $this;
	}

	public function getPath(): string
	{
		return $this->path ?? $this->computePath();
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function setParent(?self $parent): static
	{
		if (null !== $parent) {
			$this->assertNoCycle($parent);
		}
		$this->parent = $parent;
		$this->path = $this->computePath();

		return $this;
	}

	/**
	 * Computes the materialized `/parent/slug` path by traversing ancestors
	 * with a cycle guard.
	 */
	public function computePath(): string
	{
		$segments = [$this->getSlug()];
		$visitedObjects = [spl_object_id($this) => true];
		$visitedIds = [];
		$ownId = $this->getId();
		if (null !== $ownId) {
			$visitedIds[(string) $ownId] = true;
		}

		$cursor = $this->parent;
		while (null !== $cursor) {
			$objId = spl_object_id($cursor);
			$cursorId = $cursor->getId();
			if (isset($visitedObjects[$objId]) || (null !== $cursorId && isset($visitedIds[(string) $cursorId]))) {
				throw new \InvalidArgumentException('Folder hierarchy contains a cycle.');
			}
			$visitedObjects[$objId] = true;
			if (null !== $cursorId) {
				$visitedIds[(string) $cursorId] = true;
			}
			$segments[] = $cursor->getSlug();
			$cursor = $cursor->getParent();
		}

		return '/'.implode('/', array_reverse($segments));
	}

	public function refreshPath(): static
	{
		$this->path = $this->computePath();

		return $this;
	}

	/**
	 * Rewrites this folder's stored path when an ancestor's path changes.
	 */
	public function rewritePathPrefix(string $oldPrefix, string $newPrefix): static
	{
		$current = $this->getPath();
		$normalizedOld = rtrim($oldPrefix, '/');
		$normalizedNew = rtrim($newPrefix, '/');

		if ($current === $normalizedOld) {
			$this->path = '' !== $normalizedNew ? $normalizedNew : '/';
		} elseif (str_starts_with($current, $normalizedOld.'/')) {
			$this->path = $normalizedNew.substr($current, strlen($normalizedOld));
		}

		return $this;
	}

	/** @return Collection<int,Site> */
	public function getAccessSites(): Collection
	{
		if (!isset($this->accessSites)) {
			$this->accessSites = new ArrayCollection();
		}

		return $this->accessSites;
	}

	/** @param iterable<mixed> $sites */
	public function setAccessSites(iterable $sites): static
	{
		$collection = $this->getAccessSites();
		$collection->clear();
		$seen = [];
		foreach ($sites as $site) {
			if (!$site instanceof Site) {
				throw new \InvalidArgumentException('Folder access grants must reference sites.');
			}
			$key = null !== $site->getId() ? 'id:'.(string) $site->getId() : 'obj:'.spl_object_id($site);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$collection->add($site);
		}

		return $this;
	}

	public function isGlobalAccess(): bool
	{
		return $this->getAccessSites()->isEmpty();
	}

	public function grantsAccessTo(Site $site): bool
	{
		foreach ($this->getAccessSites() as $grantedSite) {
			if ((string) $grantedSite->getId() === (string) $site->getId()) {
				return true;
			}
		}

		return false;
	}

	public function isAccessibleToSite(Site $site): bool
	{
		return $this->isGlobalAccess() || $this->grantsAccessTo($site);
	}

	public static function slugify(string $value): string
	{
		$normalized = mb_strtolower(trim($value));
		$normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
		$normalized = trim($normalized, '-');
		if ('' === $normalized) {
			throw new \InvalidArgumentException('Folder slug must contain at least one alphanumeric character.');
		}

		return substr($normalized, 0, 255);
	}

	private function assertNoCycle(self $candidateParent): void
	{
		$ownObjId = spl_object_id($this);
		$ownId = $this->getId();
		$visitedObjects = [$ownObjId => true];
		$visitedIds = null !== $ownId ? [(string) $ownId => true] : [];

		$cursor = $candidateParent;
		while (null !== $cursor) {
			$cursorObjId = spl_object_id($cursor);
			$cursorId = $cursor->getId();
			if (isset($visitedObjects[$cursorObjId]) || (null !== $cursorId && isset($visitedIds[(string) $cursorId]))) {
				throw new \InvalidArgumentException('Cannot move a folder into itself or one of its descendants.');
			}
			$visitedObjects[$cursorObjId] = true;
			if (null !== $cursorId) {
				$visitedIds[(string) $cursorId] = true;
			}
			$cursor = $cursor->getParent();
		}
	}
}
