<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * API-first domain manager for DAM organization primitives: folders,
 * collections, and tags. Enforces {@see DigitalAssetAccessPolicy},
 * prevents folder hierarchy cycles, cascades subtree materialized paths
 * on rename/move/delete, and records audit entries.
 */
final class DigitalAssetOrganizationManager
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetFolderRepository $folders,
		private readonly DigitalAssetCollectionRepository $collections,
		private readonly DigitalAssetTagRepository $tags,
		private readonly DigitalAssetRepository $assets,
		private readonly SiteRepository $sites,
		private readonly SiteRegistry $siteRegistry,
		private readonly DigitalAssetAccessPolicy $accessPolicy,
		private readonly ?AuditRecorder $auditRecorder = null,
	) {
	}

	// ------------------------------------------------------------------
	// Folders
	// ------------------------------------------------------------------

	public function canManageFolder(User $user, DigitalAssetFolder $folder): bool
	{
		if ($this->accessPolicy->isGlobalAdmin($user)) {
			return true;
		}
		if ($folder->isGlobalAccess()) {
			return false;
		}
		foreach ($folder->getAccessSites() as $site) {
			if (!$this->accessPolicy->canManageSite($user, $site)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks whether `$user` may place manageable assets into `$folder`.
	 * Global folders (empty `accessSites`) can be used by any admin; site-scoped
	 * folders require admin access to all sites granted on the folder.
	 */
	public function canUseFolder(User $user, DigitalAssetFolder $folder): bool
	{
		if ($this->accessPolicy->isGlobalAdmin($user) || $folder->isGlobalAccess()) {
			return true;
		}

		return $this->canManageFolder($user, $folder);
	}

	/**
	 * @return list<DigitalAssetFolder>
	 */
	public function listManageableFolders(User $user): array
	{
		if ($this->accessPolicy->isGlobalAdmin($user)) {
			return $this->folders->findAllOrdered();
		}

		return $this->folders->findManageableBySites($this->accessPolicy->getManageableSiteIds($user));
	}

	/**
	 * @param list<mixed> $siteIds
	 */
	public function createFolder(
		string $name,
		?DigitalAssetFolder $parent,
		array $siteIds,
		User $user,
		?string $slug = null,
	): DigitalAssetFolder {
		if (null !== $parent && !$this->canManageFolder($user, $parent)) {
			throw new AccessDeniedHttpException('You cannot create subfolders inside a folder you do not administer.');
		}

		$resolvedSites = $this->resolveFolderSites($siteIds, $user);

		$folder = new DigitalAssetFolder($name, $parent, $slug);
		$folder->setSiteId(0);
		$folder->setCreatorId($this->creatorId($user));
		$folder->setCreatedDate(new \DateTimeImmutable());
		$folder->setAccessSites($resolvedSites);

		$this->assertUniqueSiblingSlug($parent, $folder->getSlug(), null);

		$this->entityManager->persist($folder);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Created digital asset folder "%s" (%s)', $folder->getName(), $folder->getPath()),
			'created',
			'digital_asset_folder',
			$folder->getId(),
			null,
			$this->serializeFolder($folder),
		);

		return $folder;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function updateFolder(DigitalAssetFolder $folder, array $payload, User $user): DigitalAssetFolder
	{
		if (!$this->canManageFolder($user, $folder)) {
			throw new AccessDeniedHttpException('You cannot manage folders shared with sites you do not administer.');
		}

		$before = $this->serializeFolder($folder);
		$oldPath = $folder->getPath();

		$nameProvided = array_key_exists('name', $payload);
		$slugProvided = array_key_exists('slug', $payload) && null !== $payload['slug'] && '' !== trim((string) $payload['slug']);

		if ($nameProvided) {
			$folder->setName((string) $payload['name']);
		}

		if ($slugProvided) {
			$folder->setSlug((string) $payload['slug']);
		} elseif ($nameProvided) {
			$folder->setSlug($folder->getName());
		}

		if (array_key_exists('parentId', $payload)) {
			$targetParent = $this->resolveOptionalParent($payload['parentId'], $user);
			$this->assertNoFolderCycle($folder, $targetParent);
			$folder->setParent($targetParent);
		}

		if (array_key_exists('siteIds', $payload)) {
			if (!is_array($payload['siteIds'])) {
				throw new \InvalidArgumentException('Site grants must be a list of site IDs.');
			}
			$folder->setAccessSites($this->resolveFolderSites(array_values($payload['siteIds']), $user));
		}

		$this->assertUniqueSiblingSlug($folder->getParent(), $folder->getSlug(), $folder);
		$folder->refreshPath();

		$newPath = $folder->getPath();
		if ($newPath !== $oldPath) {
			$this->cascadeSubtreePaths($oldPath, $newPath, $folder);
		}

		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Updated digital asset folder "%s" (%s)', $folder->getName(), $folder->getPath()),
			'updated',
			'digital_asset_folder',
			$folder->getId(),
			$before,
			$this->serializeFolder($folder),
		);

		return $folder;
	}

	public function deleteFolder(DigitalAssetFolder $folder, User $user): void
	{
		if (!$this->canManageFolder($user, $folder)) {
			throw new AccessDeniedHttpException('You cannot manage folders shared with sites you do not administer.');
		}

		$folderPath = $folder->getPath();
		$directChildren = $this->folders->findChildren($folder);
		$descendants = $this->folders->findDescendantsByPathPrefix($folderPath);
		foreach ([...$directChildren, ...$descendants] as $descendant) {
			if ($this->isSameEntity($descendant, $folder)) {
				continue;
			}
			if (!$this->canManageFolder($user, $descendant)) {
				throw new AccessDeniedHttpException('You cannot delete a folder containing subfolders shared with sites you do not administer.');
			}
		}

		$before = $this->serializeFolder($folder);
		$folderId = $folder->getId();
		$folderName = $folder->getName();

		// Promote direct children to root (resolving any root slug collisions) and cascade updated paths before deleting.
		$claimedRootSlugs = [];
		foreach ($directChildren as $child) {
			$childOldPath = $child->getPath();
			$resolvedSlug = $this->resolveUniquePromotedRootSlug($child, $folder, $claimedRootSlugs);
			$claimedRootSlugs[$resolvedSlug] = true;
			if ($resolvedSlug !== $child->getSlug()) {
				$child->setSlug($resolvedSlug);
			}
			$child->setParent(null);
			$childNewPath = $child->getPath();
			if ($childNewPath !== $childOldPath) {
				$this->cascadeSubtreePaths($childOldPath, $childNewPath, $child);
			}
		}

		$this->entityManager->remove($folder);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Deleted digital asset folder "%s" (%s)', $folderName, $folderPath),
			'deleted',
			'digital_asset_folder',
			$folderId,
			$before,
			null,
		);
	}

	/**
	 * Verifies that assigning `$targetParent` as the parent of `$folder` does
	 * not create a self-reference or cycle in the folder tree.
	 */
	public function assertNoFolderCycle(DigitalAssetFolder $folder, ?DigitalAssetFolder $targetParent): void
	{
		if (null === $targetParent) {
			return;
		}

		$folderId = $folder->getId();
		$folderPath = rtrim($folder->getPath(), '/');
		$targetPath = rtrim($targetParent->getPath(), '/');

		if (
			$folder === $targetParent
			|| (null !== $folderId && null !== $targetParent->getId() && (string) $folderId === (string) $targetParent->getId())
		) {
			throw new \InvalidArgumentException('A folder cannot be its own parent.');
		}

		if ('' !== $folderPath && ($targetPath === $folderPath || str_starts_with($targetPath, $folderPath.'/'))) {
			throw new \InvalidArgumentException('Cannot move a folder into one of its descendants.');
		}

		$visited = [spl_object_id($folder) => true];
		$cursor = $targetParent;
		while (null !== $cursor) {
			if (
				isset($visited[spl_object_id($cursor)])
				|| (null !== $folderId && null !== $cursor->getId() && (string) $folderId === (string) $cursor->getId())
			) {
				throw new \InvalidArgumentException('Cannot move a folder into one of its descendants.');
			}
			$visited[spl_object_id($cursor)] = true;
			$cursor = $cursor->getParent();
		}
	}

	// ------------------------------------------------------------------
	// Collections
	// ------------------------------------------------------------------

	/**
	 * @return list<DigitalAssetCollection>
	 */
	public function listCollections(): array
	{
		return $this->collections->findAllOrdered();
	}

	public function createCollection(
		string $name,
		?string $description,
		User $user,
		?string $slug = null,
	): DigitalAssetCollection {
		$collection = new DigitalAssetCollection($name, $slug, $description);
		$collection->setSiteId(0);
		$collection->setCreatorId($this->creatorId($user));
		$collection->setCreatedDate(new \DateTimeImmutable());

		$this->assertUniqueCollectionSlug($collection->getSlug(), null);

		$this->entityManager->persist($collection);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Created digital asset collection "%s"', $collection->getName()),
			'created',
			'digital_asset_collection',
			$collection->getId(),
			null,
			$this->serializeCollection($collection),
		);

		return $collection;
	}

	public function canManageCollection(User $user, DigitalAssetCollection $collection): bool
	{
		if ($this->accessPolicy->isGlobalAdmin($user)) {
			return true;
		}
		$userId = $user->getId();
		if (null === $userId || (string) $collection->getCreatorId() !== (string) $userId) {
			return false;
		}
		foreach ($collection->getAssets() as $asset) {
			if (!$this->accessPolicy->canManageAsset($user, $asset)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function updateCollection(DigitalAssetCollection $collection, array $payload, User $user): DigitalAssetCollection
	{
		if (!$this->accessPolicy->isGlobalAdmin($user)) {
			throw new AccessDeniedHttpException('Only global administrators can update global collections.');
		}

		$before = $this->serializeCollection($collection);

		$nameProvided = array_key_exists('name', $payload);
		$slugProvided = array_key_exists('slug', $payload) && null !== $payload['slug'] && '' !== trim((string) $payload['slug']);

		if ($nameProvided) {
			$collection->setName((string) $payload['name']);
		}
		if ($slugProvided) {
			$collection->setSlug((string) $payload['slug']);
		} elseif ($nameProvided) {
			$collection->setSlug($collection->getName());
		}
		if (array_key_exists('description', $payload)) {
			$collection->setDescription(null === $payload['description'] ? null : (string) $payload['description']);
		}

		$this->assertUniqueCollectionSlug($collection->getSlug(), $collection);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Updated digital asset collection "%s"', $collection->getName()),
			'updated',
			'digital_asset_collection',
			$collection->getId(),
			$before,
			$this->serializeCollection($collection),
		);

		return $collection;
	}

	public function deleteCollection(DigitalAssetCollection $collection, User $user): void
	{
		if (!$this->accessPolicy->isGlobalAdmin($user)) {
			throw new AccessDeniedHttpException('Only global administrators can delete global collections.');
		}

		$before = $this->serializeCollection($collection);
		$id = $collection->getId();
		$name = $collection->getName();

		$this->entityManager->remove($collection);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Deleted digital asset collection "%s"', $name),
			'deleted',
			'digital_asset_collection',
			$id,
			$before,
			null,
		);
	}

	/**
	 * Adds one or more assets to `$collection`, enforcing collection manageability
	 * and `canManageAsset` on every asset.
	 *
	 * @param list<mixed> $assetIds
	 *
	 * @return list<int|string> Added asset IDs
	 */
	public function addAssetsToCollection(DigitalAssetCollection $collection, array $assetIds, User $user): array
	{
		if (!$this->canManageCollection($user, $collection)) {
			throw new AccessDeniedHttpException('You cannot modify collections you do not administer.');
		}

		$normalizedIds = $this->normalizePositiveIdList($assetIds, 'assetIds');
		if ([] === $normalizedIds) {
			throw new \InvalidArgumentException('At least one asset ID is required.');
		}

		$resolvedAssets = [];
		foreach ($normalizedIds as $assetId) {
			$asset = $this->assets->find($assetId);
			if (!$asset instanceof AbstractDigitalAsset) {
				throw new \InvalidArgumentException(sprintf('Digital asset #%s was not found.', $assetId));
			}
			if (!$this->accessPolicy->canManageAsset($user, $asset)) {
				throw new AccessDeniedHttpException(sprintf('You cannot manage digital asset #%s.', $assetId));
			}
			$resolvedAssets[] = $asset;
		}

		$added = [];
		foreach ($resolvedAssets as $asset) {
			$collection->addAsset($asset);
			$added[] = $asset->getId() ?? '';
		}

		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Added %d asset(s) to collection "%s"', count($added), $collection->getName()),
			'updated',
			'digital_asset_collection',
			$collection->getId(),
			null,
			null,
			['addedAssetIds' => $added],
		);

		return $added;
	}

	public function removeAssetFromCollection(DigitalAssetCollection $collection, AbstractDigitalAsset $asset, User $user): void
	{
		if (!$this->canManageCollection($user, $collection)) {
			throw new AccessDeniedHttpException('You cannot modify collections you do not administer.');
		}
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot manage assets shared with sites you do not administer.');
		}

		$collection->removeAsset($asset);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Removed asset #%s from collection "%s"', (string) $asset->getId(), $collection->getName()),
			'updated',
			'digital_asset_collection',
			$collection->getId(),
			null,
			null,
			['removedAssetId' => $asset->getId()],
		);
	}

	// ------------------------------------------------------------------
	// Tags
	// ------------------------------------------------------------------

	/**
	 * @return list<DigitalAssetTag>
	 */
	public function listTags(?string $namespace = null, ?string $query = null): array
	{
		return $this->tags->findAllOrdered($namespace, $query);
	}

	public function findOrCreateTag(
		string $name,
		User $user,
		string $namespace = DigitalAssetTag::DEFAULT_NAMESPACE,
		?string $slug = null,
	): DigitalAssetTag {
		$normalizedNamespace = DigitalAssetTag::normalizeNamespace($namespace);
		$normalizedSlug = DigitalAssetTag::slugify(null !== $slug && '' !== trim($slug) ? $slug : $name);

		$existing = $this->tags->findOneByNamespaceAndSlug($normalizedNamespace, $normalizedSlug);
		if ($existing instanceof DigitalAssetTag) {
			return $existing;
		}

		return $this->persistTag($name, $normalizedNamespace, $normalizedSlug, $user);
	}

	public function createTag(
		string $name,
		User $user,
		string $namespace = DigitalAssetTag::DEFAULT_NAMESPACE,
		?string $slug = null,
	): DigitalAssetTag {
		$normalizedNamespace = DigitalAssetTag::normalizeNamespace($namespace);
		$normalizedSlug = DigitalAssetTag::slugify(null !== $slug && '' !== trim($slug) ? $slug : $name);

		$existing = $this->tags->findOneByNamespaceAndSlug($normalizedNamespace, $normalizedSlug);
		if ($existing instanceof DigitalAssetTag) {
			throw new \InvalidArgumentException(sprintf('A tag with slug "%s" already exists in namespace "%s".', $normalizedSlug, $normalizedNamespace));
		}

		return $this->persistTag($name, $normalizedNamespace, $normalizedSlug, $user);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function updateTag(DigitalAssetTag $tag, array $payload, User $user): DigitalAssetTag
	{
		if (!$this->accessPolicy->isGlobalAdmin($user)) {
			throw new AccessDeniedHttpException('Only global administrators can update global tags.');
		}

		$before = $this->serializeTag($tag);

		$nameProvided = array_key_exists('name', $payload);
		$slugProvided = array_key_exists('slug', $payload) && null !== $payload['slug'] && '' !== trim((string) $payload['slug']);

		if ($nameProvided) {
			$tag->setName((string) $payload['name']);
		}
		if (array_key_exists('namespace', $payload)) {
			$tag->setNamespace((string) $payload['namespace']);
		}
		if ($slugProvided) {
			$tag->setSlug((string) $payload['slug']);
		} elseif ($nameProvided) {
			$tag->setSlug($tag->getName());
		}

		$existing = $this->tags->findOneByNamespaceAndSlug($tag->getNamespace(), $tag->getSlug());
		if ($existing instanceof DigitalAssetTag && !$this->isSameEntity($existing, $tag)) {
			throw new \InvalidArgumentException(sprintf('A tag with slug "%s" already exists in namespace "%s".', $tag->getSlug(), $tag->getNamespace()));
		}

		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Updated digital asset tag "%s:%s"', $tag->getNamespace(), $tag->getSlug()),
			'updated',
			'digital_asset_tag',
			$tag->getId(),
			$before,
			$this->serializeTag($tag),
		);

		return $tag;
	}

	public function deleteTag(DigitalAssetTag $tag, User $user): void
	{
		if (!$this->accessPolicy->isGlobalAdmin($user)) {
			throw new AccessDeniedHttpException('Only global administrators can delete global tags.');
		}

		$before = $this->serializeTag($tag);
		$id = $tag->getId();
		$label = $tag->getNamespace().':'.$tag->getSlug();

		$this->entityManager->remove($tag);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Deleted digital asset tag "%s"', $label),
			'deleted',
			'digital_asset_tag',
			$id,
			$before,
			null,
		);
	}

	// ------------------------------------------------------------------
	// Serialization helpers
	// ------------------------------------------------------------------

	/** @return array<string,mixed> */
	public function serializeFolder(DigitalAssetFolder $folder): array
	{
		$siteIds = [];
		foreach ($folder->getAccessSites() as $site) {
			$siteIds[] = $site->getId();
		}

		return [
			'id' => $folder->getId(),
			'name' => $folder->getName(),
			'slug' => $folder->getSlug(),
			'path' => $folder->getPath(),
			'parentId' => $folder->getParent()?->getId(),
			'siteIds' => $siteIds,
		];
	}

	/** @return array<string,mixed> */
	public function serializeCollection(DigitalAssetCollection $collection, ?User $viewer = null): array
	{
		$scopeToViewer = $viewer instanceof User && !$this->accessPolicy->isGlobalAdmin($viewer);
		$assetIds = [];
		foreach ($collection->getAssets() as $asset) {
			if ($scopeToViewer && !$this->accessPolicy->canManageAsset($viewer, $asset)) {
				continue;
			}
			$id = $asset->getId();
			if (null !== $id) {
				$assetIds[] = $id;
			}
		}

		return [
			'id' => $collection->getId(),
			'name' => $collection->getName(),
			'slug' => $collection->getSlug(),
			'description' => $collection->getDescription(),
			'assetCount' => count($assetIds),
			'assetIds' => $assetIds,
		];
	}

	/** @return array<string,mixed> */
	public function serializeTag(DigitalAssetTag $tag): array
	{
		return [
			'id' => $tag->getId(),
			'name' => $tag->getName(),
			'slug' => $tag->getSlug(),
			'namespace' => $tag->getNamespace(),
		];
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	private function persistTag(string $name, string $namespace, string $slug, User $user): DigitalAssetTag
	{
		$tag = new DigitalAssetTag($name, $namespace, $slug);
		$tag->setSiteId(0);
		$tag->setCreatorId($this->creatorId($user));
		$tag->setCreatedDate(new \DateTimeImmutable());

		$this->entityManager->persist($tag);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			sprintf('Created digital asset tag "%s:%s"', $tag->getNamespace(), $tag->getSlug()),
			'created',
			'digital_asset_tag',
			$tag->getId(),
			null,
			$this->serializeTag($tag),
		);

		return $tag;
	}

	private function cascadeSubtreePaths(string $oldPath, string $newPath, DigitalAssetFolder $rootFolder): void
	{
		foreach ($this->folders->findDescendantsByPathPrefix($oldPath) as $descendant) {
			if ($this->isSameEntity($descendant, $rootFolder)) {
				continue;
			}
			$descendant->rewritePathPrefix($oldPath, $newPath);
		}
	}

	private function resolveOptionalParent(mixed $parentId, User $user): ?DigitalAssetFolder
	{
		if (null === $parentId || '' === $parentId) {
			return null;
		}
		if (!is_int($parentId) && !(is_string($parentId) && ctype_digit(trim($parentId)))) {
			throw new \InvalidArgumentException('parentId must be a positive integer or null.');
		}
		$parent = $this->folders->find(trim((string) $parentId));
		if (!$parent instanceof DigitalAssetFolder) {
			throw new \InvalidArgumentException('Target parent folder was not found.');
		}
		if (!$this->canManageFolder($user, $parent)) {
			throw new AccessDeniedHttpException('You cannot move a folder into a parent folder you do not administer.');
		}

		return $parent;
	}

	/**
	 * @param list<mixed> $siteIds
	 *
	 * @return list<Site>
	 */
	private function resolveFolderSites(array $siteIds, User $user): array
	{
		$ids = $this->normalizePositiveIdList($siteIds, 'siteIds');
		if ([] === $ids) {
			if ($this->accessPolicy->isGlobalAdmin($user)) {
				return [];
			}
			$currentSite = $this->siteRegistry::getCurrent();
			if (!$this->accessPolicy->canManageSite($user, $currentSite)) {
				throw new AccessDeniedHttpException('You cannot grant folder access to this site.');
			}

			return [$currentSite];
		}

		$sites = $this->sites->findBy(['id' => $ids], ['id' => 'ASC'], null, null, ['filterBySite' => false]);
		if (count($sites) !== count($ids)) {
			throw new \InvalidArgumentException('One or more selected sites do not exist.');
		}
		if (!$this->accessPolicy->canManageAllSites($user, $sites)) {
			throw new AccessDeniedHttpException('You can only grant folder access to sites you administer.');
		}

		return $sites;
	}

	private function assertUniqueSiblingSlug(?DigitalAssetFolder $parent, string $slug, ?DigitalAssetFolder $ignore): void
	{
		$existing = $this->folders->findByParentAndSlug($parent, $slug);
		if ($existing instanceof DigitalAssetFolder && (null === $ignore || !$this->isSameEntity($existing, $ignore))) {
			throw new \InvalidArgumentException(sprintf('A folder with slug "%s" already exists in this parent folder.', $slug));
		}
	}

	/**
	 * @param array<string,bool> $claimedRootSlugs
	 */
	private function resolveUniquePromotedRootSlug(
		DigitalAssetFolder $child,
		DigitalAssetFolder $deletingParent,
		array $claimedRootSlugs,
	): string {
		$baseSlug = $child->getSlug();
		$candidate = $baseSlug;
		for ($suffix = 2; $suffix <= 1002; ++$suffix) {
			if (!isset($claimedRootSlugs[$candidate])) {
				$existing = $this->folders->findByParentAndSlug(null, $candidate);
				if (
					!$existing instanceof DigitalAssetFolder
					|| $this->isSameEntity($existing, $child)
					|| $this->isSameEntity($existing, $deletingParent)
				) {
					return $candidate;
				}
			}
			$suffixStr = '-'.$suffix;
			$candidate = mb_substr($baseSlug, 0, max(1, 255 - strlen($suffixStr))).$suffixStr;
		}

		throw new \InvalidArgumentException(sprintf('Unable to allocate a unique root slug for promoted folder "%s".', $baseSlug));
	}

	private function assertUniqueCollectionSlug(string $slug, ?DigitalAssetCollection $ignore): void
	{
		$existing = $this->collections->findBySlug($slug);
		if ($existing instanceof DigitalAssetCollection && (null === $ignore || !$this->isSameEntity($existing, $ignore))) {
			throw new \InvalidArgumentException(sprintf('A collection with slug "%s" already exists.', $slug));
		}
	}

	/**
	 * @param list<mixed> $values
	 *
	 * @return list<string>
	 */
	private function normalizePositiveIdList(array $values, string $fieldName): array
	{
		$ids = [];
		foreach ($values as $value) {
			if (!is_string($value) && !is_int($value)) {
				throw new \InvalidArgumentException(sprintf('%s must contain positive integers.', $fieldName));
			}
			$trimmed = trim((string) $value);
			if ('' === $trimmed) {
				continue;
			}
			if (!ctype_digit($trimmed) || '0' === $trimmed) {
				throw new \InvalidArgumentException(sprintf('%s must contain positive integers.', $fieldName));
			}
			$ids[] = $trimmed;
		}

		return array_values(array_unique($ids));
	}

	private function creatorId(User $user): int|string
	{
		return $user->getId() ?? 0;
	}

	private function isSameEntity(object $a, object $b): bool
	{
		if ($a === $b) {
			return true;
		}
		if (method_exists($a, 'getId') && method_exists($b, 'getId')) {
			$aId = $a->getId();
			$bId = $b->getId();
			if (null !== $aId && null !== $bId) {
				return (string) $aId === (string) $bId;
			}
		}

		return false;
	}
}
