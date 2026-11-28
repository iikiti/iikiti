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
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Executes bulk operations on digital assets while enforcing
 * {@see DigitalAssetAccessPolicy::canManageAsset()} on every asset individually.
 * Failures on individual assets are collected without aborting valid items.
 */
final class DigitalAssetBulkActionService
{
	public const ACTION_TAG_ADD = 'tag_add';
	public const ACTION_TAG_REMOVE = 'tag_remove';
	public const ACTION_MOVE_FOLDER = 'move_folder';
	public const ACTION_COLLECTION_ADD = 'collection_add';
	public const ACTION_COLLECTION_REMOVE = 'collection_remove';
	public const ACTION_SET_VISIBILITY = 'set_visibility';
	public const ACTION_SET_SITE_GRANTS = 'set_site_grants';
	public const ACTION_REPROCESS = 'reprocess';
	public const ACTION_DELETE = 'delete';

	/** @var list<string> */
	public const ACTIONS = [
		self::ACTION_TAG_ADD,
		self::ACTION_TAG_REMOVE,
		self::ACTION_MOVE_FOLDER,
		self::ACTION_COLLECTION_ADD,
		self::ACTION_COLLECTION_REMOVE,
		self::ACTION_SET_VISIBILITY,
		self::ACTION_SET_SITE_GRANTS,
		self::ACTION_REPROCESS,
		self::ACTION_DELETE,
	];

	public const MAX_BATCH_SIZE = 500;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetRepository $assets,
		private readonly DigitalAssetFolderRepository $folders,
		private readonly DigitalAssetCollectionRepository $collections,
		private readonly DigitalAssetTagRepository $tags,
		private readonly SiteRepository $sites,
		private readonly DigitalAssetAccessPolicy $accessPolicy,
		private readonly DigitalAssetManager $assetManager,
		private readonly DigitalAssetOrganizationManager $organizationManager,
		private readonly ?AuditRecorder $auditRecorder = null,
		private readonly ?EventDispatcherInterface $eventDispatcher = null,
	) {
	}

	/**
	 * @param list<mixed>         $assetIds
	 * @param array<string,mixed> $params
	 *
	 * @return array{
	 *     succeeded: list<int|string>,
	 *     failed: list<array{id: int|string, reason: string, usages?: list<array<string,mixed>>}>
	 * }
	 */
	public function execute(string $action, array $assetIds, array $params, User $user): array
	{
		$action = strtolower(trim($action));
		if (!in_array($action, self::ACTIONS, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported bulk action "%s".', $action));
		}

		$normalizedIds = $this->normalizeAssetIds($assetIds);
		if ([] === $normalizedIds) {
			throw new \InvalidArgumentException('At least one asset ID is required.');
		}
		if (count($normalizedIds) > self::MAX_BATCH_SIZE) {
			throw new \InvalidArgumentException(sprintf('Bulk operations support at most %d assets per request.', self::MAX_BATCH_SIZE));
		}

		// Pre-validate action parameters once before iterating over assets.
		$targetTags = [];
		$targetFolder = null;
		$targetCollection = null;
		$targetVisibility = null;
		$targetSites = [];

		switch ($action) {
			case self::ACTION_TAG_ADD:
				$targetTags = $this->resolveTagsForAdd($params, $user);
				break;
			case self::ACTION_TAG_REMOVE:
				$targetTags = $this->resolveTagsForRemove($params);
				break;
			case self::ACTION_MOVE_FOLDER:
				$targetFolder = $this->resolveTargetFolder($params, $user);
				break;
			case self::ACTION_COLLECTION_ADD:
			case self::ACTION_COLLECTION_REMOVE:
				$targetCollection = $this->resolveTargetCollection($params, $user);
				break;
			case self::ACTION_SET_VISIBILITY:
				$targetVisibility = $this->resolveVisibility($params);
				break;
			case self::ACTION_SET_SITE_GRANTS:
				$targetSites = $this->resolveTargetSites($params, $user);
				break;
			case self::ACTION_REPROCESS:
			case self::ACTION_DELETE:
				break;
		}

		$succeeded = [];
		$failed = [];
		$needsFlush = false;
		/** @var list<AbstractDigitalAsset> $updatedAssets */
		$updatedAssets = [];

		foreach ($normalizedIds as $assetId) {
			$asset = $this->assets->find($assetId);
			if (!$asset instanceof AbstractDigitalAsset) {
				$failed[] = [
					'id' => $assetId,
					'reason' => 'Asset not found.',
				];
				continue;
			}

			if (!$this->accessPolicy->canManageAsset($user, $asset)) {
				$failed[] = [
					'id' => $asset->getId() ?? $assetId,
					'reason' => 'You cannot manage assets shared with sites you do not administer.',
				];
				continue;
			}

			try {
				switch ($action) {
					case self::ACTION_TAG_ADD:
						foreach ($targetTags as $tag) {
							$asset->addTag($tag);
						}
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_TAG_REMOVE:
						foreach ($targetTags as $tag) {
							$asset->removeTag($tag);
						}
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_MOVE_FOLDER:
						$asset->setFolder($targetFolder);
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_COLLECTION_ADD:
						$targetCollection?->addAsset($asset);
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_COLLECTION_REMOVE:
						$targetCollection?->removeAsset($asset);
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_SET_VISIBILITY:
						$asset->setVisibility((string) $targetVisibility);
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_SET_SITE_GRANTS:
						$asset->setAccessSites($targetSites);
						$needsFlush = true;
						$updatedAssets[] = $asset;
						break;

					case self::ACTION_REPROCESS:
						if (!$asset instanceof UploadedDigitalAsset) {
							$failed[] = [
								'id' => $asset->getId() ?? $assetId,
								'reason' => 'Only uploaded assets can be reprocessed.',
							];
							continue 2;
						}
						$this->assetManager->queueProcessing($asset, resetCompletedWorkflows: true);
						break;

					case self::ACTION_DELETE:
						$forceDelete = $this->parseBool($params['force'] ?? false);
						$this->assetManager->delete($asset, $user, $forceDelete);
						break;
				}

				$succeeded[] = $asset->getId() ?? $assetId;
			} catch (\iikiti\CMS\Exception\AssetInUseException $exception) {
				$failed[] = [
					'id' => $asset->getId() ?? $assetId,
					'reason' => $exception->getMessage(),
					'usages' => $exception->getUsages(),
				];
			} catch (\Throwable $exception) {
				$entry = [
					'id' => $asset->getId() ?? $assetId,
					'reason' => $exception->getMessage(),
				];
				if (method_exists($exception, 'getUsages')) {
					$usages = $exception->getUsages();
					if (is_array($usages)) {
						/** @var list<array<string,mixed>> $usages */
						$entry['usages'] = array_values($usages);
					}
				}
				$failed[] = $entry;
			}
		}

		if ($needsFlush && [] !== $succeeded) {
			$this->entityManager->flush();
		}

		if (null !== $this->eventDispatcher && [] !== $updatedAssets) {
			foreach ($updatedAssets as $updatedAsset) {
				$this->eventDispatcher->dispatch(new DigitalAssetEvent($updatedAsset), DigitalAssetEvents::UPDATED);
			}
		}

		$this->auditRecorder?->record(
			sprintf(
				'Bulk digital asset action "%s": %d succeeded, %d failed',
				$action,
				count($succeeded),
				count($failed),
			),
			self::ACTION_DELETE === $action ? 'deleted' : 'updated',
			'digital_asset_bulk',
			null,
			null,
			null,
			[
				'bulkAction' => $action,
				'requestedCount' => count($normalizedIds),
				'succeededCount' => count($succeeded),
				'failedCount' => count($failed),
				'succeededIds' => $succeeded,
				'failedIds' => array_map(static fn (array $item): int|string => $item['id'], $failed),
			],
		);

		return [
			'succeeded' => $succeeded,
			'failed' => $failed,
		];
	}

	/**
	 * @param list<mixed> $assetIds
	 *
	 * @return list<string>
	 */
	private function normalizeAssetIds(array $assetIds): array
	{
		$ids = [];
		foreach ($assetIds as $rawId) {
			if (!is_int($rawId) && !is_string($rawId)) {
				throw new \InvalidArgumentException('Asset IDs must be positive integers.');
			}
			$trimmed = trim((string) $rawId);
			if ('' === $trimmed) {
				continue;
			}
			if (!ctype_digit($trimmed) || '0' === $trimmed) {
				throw new \InvalidArgumentException('Asset IDs must be positive integers.');
			}
			$ids[] = $trimmed;
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return list<DigitalAssetTag>
	 */
	private function resolveTagsForAdd(array $params, User $user): array
	{
		$resolved = [];
		$seenKeys = [];

		if (isset($params['tagIds']) && is_array($params['tagIds']) && [] !== $params['tagIds']) {
			$ids = $this->normalizePositiveIdList($params['tagIds'], 'tagIds');
			$found = $this->tags->findByIds($ids);
			if (count($found) !== count($ids)) {
				throw new \InvalidArgumentException('One or more specified tags do not exist.');
			}
			foreach ($found as $tag) {
				$key = $tag->getNamespace().':'.$tag->getSlug();
				if (!isset($seenKeys[$key])) {
					$seenKeys[$key] = true;
					$resolved[] = $tag;
				}
			}
		}

		if (isset($params['tagNames']) && is_array($params['tagNames']) && [] !== $params['tagNames']) {
			$namespace = isset($params['namespace']) && is_string($params['namespace'])
				? $params['namespace']
				: DigitalAssetTag::DEFAULT_NAMESPACE;
			foreach ($params['tagNames'] as $rawName) {
				if (!is_string($rawName) || '' === trim($rawName)) {
					throw new \InvalidArgumentException('tagNames must contain non-empty strings.');
				}
				$tag = $this->organizationManager->findOrCreateTag($rawName, $user, $namespace);
				$key = $tag->getNamespace().':'.$tag->getSlug();
				if (!isset($seenKeys[$key])) {
					$seenKeys[$key] = true;
					$resolved[] = $tag;
				}
			}
		}

		if ([] === $resolved) {
			throw new \InvalidArgumentException('tag_add requires non-empty tagIds or tagNames.');
		}

		return $resolved;
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return list<DigitalAssetTag>
	 */
	private function resolveTagsForRemove(array $params): array
	{
		if (!isset($params['tagIds']) || !is_array($params['tagIds']) || [] === $params['tagIds']) {
			throw new \InvalidArgumentException('tag_remove requires a non-empty tagIds list.');
		}
		$ids = $this->normalizePositiveIdList($params['tagIds'], 'tagIds');
		$found = $this->tags->findByIds($ids);
		if (count($found) !== count($ids)) {
			throw new \InvalidArgumentException('One or more specified tags do not exist.');
		}

		return $found;
	}

	/** @param array<string,mixed> $params */
	private function resolveTargetFolder(array $params, User $user): ?DigitalAssetFolder
	{
		if (!array_key_exists('folderId', $params)) {
			throw new \InvalidArgumentException('move_folder requires a folderId parameter (or null to unfile).');
		}
		$raw = $params['folderId'];
		if (null === $raw || '' === $raw) {
			return null;
		}
		if (!is_int($raw) && !(is_string($raw) && ctype_digit(trim($raw)))) {
			throw new \InvalidArgumentException('folderId must be a positive integer or null.');
		}
		$folder = $this->folders->find(trim((string) $raw));
		if (!$folder instanceof DigitalAssetFolder) {
			throw new \InvalidArgumentException('Target folder was not found.');
		}
		if (!$this->organizationManager->canUseFolder($user, $folder)) {
			throw new AccessDeniedHttpException('You cannot move assets into a folder you do not administer.');
		}

		return $folder;
	}

	/** @param array<string,mixed> $params */
	private function resolveTargetCollection(array $params, User $user): DigitalAssetCollection
	{
		$raw = $params['collectionId'] ?? null;
		if (!is_int($raw) && !(is_string($raw) && ctype_digit(trim($raw)))) {
			throw new \InvalidArgumentException('collectionId must be a positive integer.');
		}
		$collection = $this->collections->find(trim((string) $raw));
		if (!$collection instanceof DigitalAssetCollection) {
			throw new \InvalidArgumentException('Target collection was not found.');
		}
		if (!$this->organizationManager->canManageCollection($user, $collection)) {
			throw new AccessDeniedHttpException('You do not have permission to modify this collection.');
		}

		return $collection;
	}

	/** @param array<string,mixed> $params */
	private function resolveVisibility(array $params): string
	{
		$raw = $params['visibility'] ?? null;
		if (!is_string($raw)) {
			throw new \InvalidArgumentException('Visibility must be private or public.');
		}
		$visibility = strtolower(trim($raw));
		if (!in_array($visibility, ['private', 'public'], true)) {
			throw new \InvalidArgumentException('Visibility must be private or public.');
		}

		return $visibility;
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return list<Site>
	 */
	private function resolveTargetSites(array $params, User $user): array
	{
		if (!isset($params['siteIds']) || !is_array($params['siteIds'])) {
			throw new \InvalidArgumentException('set_site_grants requires a non-empty siteIds list.');
		}
		$ids = $this->normalizePositiveIdList($params['siteIds'], 'siteIds');
		if ([] === $ids) {
			throw new \InvalidArgumentException('set_site_grants requires at least one site ID.');
		}
		$sites = $this->sites->findBy(['id' => $ids], ['id' => 'ASC'], null, null, ['filterBySite' => false]);
		if (count($sites) !== count($ids)) {
			throw new \InvalidArgumentException('One or more selected sites do not exist.');
		}
		if (!$this->accessPolicy->canManageAllSites($user, $sites)) {
			throw new AccessDeniedHttpException('You can only grant asset access to sites you administer.');
		}

		return $sites;
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
			if (!is_int($value) && !is_string($value)) {
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

	private function parseBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return 0 !== $value;
		}
		if (is_string($value)) {
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		}

		return false;
	}
}
