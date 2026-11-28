<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageReference;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Exception\AssetInUseException;
use iikiti\CMS\Exception\DuplicateAssetException;
use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use iikiti\CMS\Value\LocalizedValue;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetManager
{
	public const DUPLICATE_POLICY_ALLOW = 'allow';
	public const DUPLICATE_POLICY_REJECT = 'reject';
	public const DUPLICATE_POLICY_REUSE = 'reuse';

	/** @var list<string> */
	public const ALLOWED_DUPLICATE_POLICIES = [
		self::DUPLICATE_POLICY_ALLOW,
		self::DUPLICATE_POLICY_REJECT,
		self::DUPLICATE_POLICY_REUSE,
	];

	private readonly AssetMimeTypeRegistry $mimeRegistry;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly SiteRepository $sites,
		private readonly SiteRegistry $siteRegistry,
		private readonly DigitalAssetAccessPolicy $accessPolicy,
		private readonly AssetStorageInterface $storage,
		private readonly MessageBusInterface $messageBus,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly AssetProcessingStateMachine $assetProcessingStateMachine,
		?AssetMimeTypeRegistry $mimeRegistry = null,
		private readonly ?DigitalAssetFolderRepository $folders = null,
		private readonly ?DigitalAssetTagRepository $tags = null,
		private readonly ?DigitalAssetRepository $assets = null,
		private readonly ?DigitalAssetVersionManager $versionManager = null,
		private readonly ?AssetUsageIndexInterface $usageIndex = null,
		private readonly ?AuditRecorder $auditRecorder = null,
	) {
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
	}

	/**
	 * @param list<mixed>              $siteIds
	 * @param list<mixed>              $tagIds
	 * @param array<string,mixed>|null $license
	 */
	public function createUpload(
		UploadedFile $file,
		string $title,
		string $visibility,
		array $siteIds,
		User $creator,
		int|string|null $folderId = null,
		array $tagIds = [],
		string $duplicatePolicy = self::DUPLICATE_POLICY_ALLOW,
		?array $license = null,
	): AbstractDigitalAsset {
		$sites = $this->resolveSites($siteIds, $creator);
		$folder = $this->resolveFolderForAdmin($folderId, $creator);
		$resolvedTags = $this->resolveTags($tagIds);

		$isGlobalAdmin = $this->accessPolicy->isGlobalAdmin($creator);
		$duplicateSiteIds = [];
		foreach ($sites as $site) {
			$sid = $site->getId();
			if (null !== $sid) {
				$duplicateSiteIds[] = $sid;
			}
		}

		return $this->persistUpload(
			$file,
			$title,
			$visibility,
			$sites,
			$creator,
			$folder,
			$resolvedTags,
			$duplicatePolicy,
			$license,
			$duplicateSiteIds,
			$isGlobalAdmin,
			true,
		);
	}

	/**
	 * Uploads a digital asset scoped strictly to the specified site (used by
	 * editor-facing endpoints after PermissionChecker authorization, without
	 * requiring ROLE_ADMIN multi-site administration privileges).
	 *
	 * @param list<mixed>              $tagIds
	 * @param array<string,mixed>|null $license
	 */
	public function createUploadForSite(
		UploadedFile $file,
		string $title,
		string $visibility,
		Site $site,
		User $creator,
		int|string|null $folderId = null,
		array $tagIds = [],
		string $duplicatePolicy = self::DUPLICATE_POLICY_ALLOW,
		?array $license = null,
	): AbstractDigitalAsset {
		$folder = $this->resolveFolderForSite($folderId, $site);
		$resolvedTags = $this->resolveTags($tagIds);
		$siteId = $site->getId();

		return $this->persistUpload(
			$file,
			$title,
			$visibility,
			[$site],
			$creator,
			$folder,
			$resolvedTags,
			$duplicatePolicy,
			$license,
			null !== $siteId ? [$siteId] : [],
			false,
			false,
		);
	}

	/**
	 * @param list<Site>               $sites
	 * @param list<DigitalAssetTag>    $tags
	 * @param array<string,mixed>|null $license
	 * @param list<int|string>         $duplicateSiteIds
	 */
	private function persistUpload(
		UploadedFile $file,
		string $title,
		string $visibility,
		array $sites,
		User $creator,
		?DigitalAssetFolder $folder = null,
		array $tags = [],
		string $duplicatePolicy = self::DUPLICATE_POLICY_ALLOW,
		?array $license = null,
		array $duplicateSiteIds = [],
		bool $isGlobalAdmin = false,
		bool $requireManageableDuplicates = false,
	): AbstractDigitalAsset {
		$normalizedPolicy = strtolower(trim($duplicatePolicy));
		if ('' === $normalizedPolicy) {
			$normalizedPolicy = self::DUPLICATE_POLICY_ALLOW;
		}
		if (!in_array($normalizedPolicy, self::ALLOWED_DUPLICATE_POLICIES, true)) {
			throw new \InvalidArgumentException('duplicatePolicy must be one of allow, reject, or reuse.');
		}

		if (!$file->isValid()) {
			throw new \InvalidArgumentException('The uploaded file is not valid.');
		}

		$mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
		if (!is_string($mimeType) || !$this->mimeRegistry->isAllowed($mimeType)) {
			throw new \InvalidArgumentException('This file type is not supported.');
		}

		$size = $file->getSize();
		$maxBytes = $this->mimeRegistry->maxBytesFor($mimeType);
		if (false === $size || $size < 1 || $size > $maxBytes) {
			throw new \InvalidArgumentException(sprintf('The file must be between 1 byte and %d bytes.', $maxBytes));
		}

		$checksum = hash_file('sha256', $file->getPathname());
		$normalizedChecksum = is_string($checksum) ? strtolower($checksum) : null;

		if (
			self::DUPLICATE_POLICY_ALLOW !== $normalizedPolicy
			&& null !== $normalizedChecksum
			&& null !== $this->assets
		) {
			$matches = $this->assets->findByChecksumForSites($normalizedChecksum, $duplicateSiteIds, $isGlobalAdmin);
			if ($requireManageableDuplicates && !$isGlobalAdmin) {
				$matches = array_values(array_filter(
					$matches,
					fn (AbstractDigitalAsset $match): bool => $this->accessPolicy->canManageAsset($creator, $match),
				));
			}
			if ([] !== $matches) {
				if (self::DUPLICATE_POLICY_REUSE === $normalizedPolicy) {
					return $matches[0];
				}
				throw new DuplicateAssetException($matches);
			}
		}

		$asset = new UploadedDigitalAsset($this->normaliseTitle($title, $file->getClientOriginalName()), $mimeType, bin2hex(random_bytes(32)));
		$asset->setSite($this->siteRegistry::getCurrent());
		$asset->setCreatorId($this->creatorId($creator));
		$asset->setCreatedDate(new \DateTimeImmutable());
		$asset->setAccessSites($sites);
		$asset->setVisibility($visibility);
		$asset->setOriginalName($file->getClientOriginalName());
		$asset->setSize($size);
		$asset->setFolder($folder);
		$asset->setCurrentVersion(1);
		if ([] !== $tags) {
			$asset->setTags($tags);
		}
		if (null !== $license) {
			$this->applyLicensePayload($asset, $license);
		}

		$asset->setChecksum($normalizedChecksum);

		$storageKey = $this->storage->storeUploadedFile($file, $mimeType);
		$asset->setStorageKey($storageKey);

		try {
			$persistInitialUpload = function () use ($asset, $creator): void {
				$this->entityManager->persist($asset);
				$this->entityManager->flush();
				$this->versionManager?->recordInitialVersion($asset, $creator);
			};
			$executedInTransaction = false;
			$this->entityManager->wrapInTransaction(static function () use (&$executedInTransaction, $persistInitialUpload): void {
				$executedInTransaction = true;
				$persistInitialUpload();
			});
			if (!$executedInTransaction) {
				$persistInitialUpload();
			}
		} catch (\Throwable $exception) {
			try {
				$this->storage->delete($storageKey);
			} catch (\Throwable) {
				// Best-effort cleanup of newly uploaded blob on persistence failure.
			}
			throw $exception;
		}

		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::CREATED);
		$this->messageBus->dispatch(new ProcessDigitalAsset($this->persistedId($asset)));

		return $asset;
	}

	/**
	 * @param list<mixed>              $siteIds
	 * @param list<mixed>              $tagIds
	 * @param array<string,mixed>|null $license
	 */
	public function createExternal(
		string $uri,
		string $title,
		string $visibility,
		array $siteIds,
		User $creator,
		?string $mimeType = null,
		int|string|null $folderId = null,
		array $tagIds = [],
		?array $license = null,
	): ExternalDigitalAsset {
		$uri = $this->validateExternalUri($uri);
		$mimeType = null === $mimeType || '' === trim($mimeType) ? 'application/octet-stream' : strtolower(trim($mimeType));
		if (strlen($mimeType) > 127 || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $mimeType)) {
			throw new \InvalidArgumentException('Invalid MIME type.');
		}

		$sites = $this->resolveSites($siteIds, $creator);
		$folder = $this->resolveFolderForAdmin($folderId, $creator);
		$resolvedTags = $this->resolveTags($tagIds);

		$asset = new ExternalDigitalAsset($this->normaliseTitle($title, $uri), $mimeType, bin2hex(random_bytes(32)));
		$asset->setSite($this->siteRegistry::getCurrent());
		$asset->setCreatorId($this->creatorId($creator));
		$asset->setCreatedDate(new \DateTimeImmutable());
		$asset->setAccessSites($sites);
		$asset->setVisibility($visibility);
		$asset->setExternalUri($uri);
		$asset->setProcessingStatus('complete');
		$asset->setFolder($folder);
		if ([] !== $resolvedTags) {
			$asset->setTags($resolvedTags);
		}
		if (null !== $license) {
			$this->applyLicensePayload($asset, $license);
		}
		$this->entityManager->persist($asset);
		$this->entityManager->flush();
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::CREATED);

		return $asset;
	}

	/** @param array<string,mixed> $payload */
	public function update(AbstractDigitalAsset $asset, array $payload, User $user): AbstractDigitalAsset
	{
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot manage assets shared with sites you do not administer.');
		}
		if (array_key_exists('title', $payload)) {
			$asset->setTitle($this->normaliseTitle((string) $payload['title'], $asset->getTitle()));
		}
		if (array_key_exists('visibility', $payload)) {
			$asset->setVisibility((string) $payload['visibility']);
		}
		if (array_key_exists('siteIds', $payload)) {
			if (!is_array($payload['siteIds'])) {
				throw new \InvalidArgumentException('Site grants must be a list of site IDs.');
			}
			$asset->setAccessSites($this->resolveSites(array_values($payload['siteIds']), $user));
		}
		if (array_key_exists('folderId', $payload)) {
			$rawFolderId = $payload['folderId'];
			if (null === $rawFolderId || '' === $rawFolderId) {
				$asset->setFolder(null);
			} elseif (is_int($rawFolderId) || is_string($rawFolderId)) {
				$asset->setFolder($this->resolveFolderForAdmin($rawFolderId, $user));
			} else {
				throw new \InvalidArgumentException('folderId must be a positive integer or null.');
			}
		}
		if (array_key_exists('tagIds', $payload)) {
			if (!is_array($payload['tagIds'])) {
				throw new \InvalidArgumentException('tagIds must be a list of tag IDs.');
			}
			$asset->setTags($this->resolveTags(array_values($payload['tagIds'])));
		}
		if (array_key_exists('uri', $payload)) {
			if (!$asset instanceof ExternalDigitalAsset) {
				throw new \InvalidArgumentException('Only external assets can change their URI.');
			}
			$asset->setExternalUri($this->validateExternalUri((string) $payload['uri']));
		}

		$focalChanged = false;
		if (array_key_exists('focalPoint', $payload)) {
			$previousFocalX = $asset->getFocalX();
			$previousFocalY = $asset->getFocalY();
			$focalPoint = $payload['focalPoint'];
			if (null === $focalPoint) {
				$asset->setFocalPoint(null, null);
			} elseif (is_array($focalPoint)) {
				if (
					!array_key_exists('x', $focalPoint) ||
					!array_key_exists('y', $focalPoint) ||
					(!is_int($focalPoint['x']) && !is_float($focalPoint['x'])) ||
					(!is_int($focalPoint['y']) && !is_float($focalPoint['y']))
				) {
					throw new \InvalidArgumentException('Focal point must provide numeric x and y coordinates between 0.0 and 1.0.');
				}
				$asset->setFocalPoint((float) $focalPoint['x'], (float) $focalPoint['y']);
			} else {
				throw new \InvalidArgumentException('Focal point must be an object with x and y coordinates or null.');
			}
			$focalChanged = ($previousFocalX !== $asset->getFocalX() || $previousFocalY !== $asset->getFocalY());
		}

		if (array_key_exists('defaultAlt', $payload)) {
			$asset->setDefaultAlt($this->normaliseLocalizedTextInput($payload['defaultAlt'], 'defaultAlt'));
		}
		if (array_key_exists('defaultCaption', $payload)) {
			$asset->setDefaultCaption($this->normaliseLocalizedTextInput($payload['defaultCaption'], 'defaultCaption'));
		}
		if (array_key_exists('description', $payload)) {
			$asset->setDescription($this->normaliseLocalizedTextInput($payload['description'], 'description'));
		}
		if (array_key_exists('license', $payload)) {
			$rawLicense = $payload['license'];
			if (null === $rawLicense) {
				$asset->setLicenseType(null);
				$asset->setLicenseHolder(null);
				$asset->setLicenseUrl(null);
				$asset->setLicenseNotes(null);
				$asset->setExpiresAt(null);
				$asset->setExpiryAction(null);
				$asset->setExpiryNotifiedAt(null);
				$asset->setExpiredHandledAt(null);
			} elseif (is_array($rawLicense)) {
				$this->applyLicensePayload($asset, $rawLicense);
			} else {
				throw new \InvalidArgumentException('license must be an object or null.');
			}
		}

		if ($focalChanged && $asset instanceof UploadedDigitalAsset) {
			$this->resetImageThumbnailWorkflowInstances($asset);
		}

		$this->entityManager->flush();

		if ($focalChanged && $asset instanceof UploadedDigitalAsset) {
			$this->queueProcessing($asset);
		}

		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::UPDATED);

		return $asset;
	}

	public function delete(AbstractDigitalAsset $asset, User $user, bool $force = false): void
	{
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot manage assets shared with sites you do not administer.');
		}

		$assetId = $asset->getId();
		$usages = [];
		if (null !== $assetId && null !== $this->usageIndex) {
			$usages = $this->usageIndex->findUsage($assetId);
			if ([] !== $usages && !$force) {
				throw new AssetInUseException(
					$assetId,
					array_map(static fn (AssetUsageReference $ref): array => $ref->toArray(), $usages),
				);
			}
		}

		$keys = [];
		if (null !== $asset->getStorageKey()) {
			$keys[] = $asset->getStorageKey();
		}
		foreach ($this->variants->findByAsset($asset) as $variant) {
			$keys[] = $variant->getStorageKey();
			$this->entityManager->remove($variant);
		}
		if (null !== $this->versionManager) {
			foreach ($this->versionManager->collectVersionStorageKeys($asset) as $versionKey) {
				$keys[] = $versionKey;
			}
		}
		if (null !== $assetId) {
			foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', $assetId) as $instance) {
				$this->entityManager->remove($instance);
			}
		}
		$this->entityManager->remove($asset);
		$this->entityManager->flush();

		foreach (array_values(array_unique($keys)) as $key) {
			if (null !== $this->versionManager && $this->versionManager->isStorageKeyReferenced($key, $assetId)) {
				continue;
			}
			try {
				$this->storage->delete($key);
			} catch (\Throwable) {
				// Best-effort cleanup of storage objects after DB deletion has committed.
			}
		}

		if ($force && [] !== $usages) {
			$this->auditRecorder?->record(
				sprintf('Force-deleted digital asset #%s despite %d active usage reference(s)', (string) ($assetId ?? ''), count($usages)),
				'deleted',
				'digital_asset',
				$assetId,
				null,
				null,
				[
					'forced' => true,
					'usageCount' => count($usages),
				],
			);
		}

		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::DELETED);
	}

	public function queueProcessing(AbstractDigitalAsset $asset, bool $resetCompletedWorkflows = false): void
	{
		if ($asset instanceof UploadedDigitalAsset) {
			if ($resetCompletedWorkflows) {
				$assetId = $asset->getId();
				if (null !== $assetId) {
					$workflowRepo = $this->entityManager->getRepository(WorkflowInstance::class);
					if (method_exists($workflowRepo, 'findBySubject')) {
						foreach ($workflowRepo->findBySubject('digital_asset', $assetId) as $instance) {
							$this->entityManager->remove($instance);
						}
					}
				}
			}
			$this->assetProcessingStateMachine->requeue($asset);
			$this->entityManager->flush();
			$this->messageBus->dispatch(new ProcessDigitalAsset($this->persistedId($asset)));
		}
	}

	private function persistedId(AbstractDigitalAsset $asset): int|string
	{
		$id = $asset->getId();

		return $id ?? throw new \LogicException('The persisted asset has no database ID.');
	}

	private function creatorId(User $creator): int|string
	{
		return $creator->getId() ?? throw new \InvalidArgumentException('The current administrator has no database ID.');
	}

	/** @param list<mixed> $siteIds
	 * @return list<Site>
	 */
	private function resolveSites(array $siteIds, User $user): array
	{
		$ids = [];
		foreach ($siteIds as $siteId) {
			if (!is_string($siteId) && !is_int($siteId)) {
				throw new \InvalidArgumentException('Site IDs must be positive integers.');
			}
			$id = trim((string) $siteId);
			if ('' !== $id) {
				if (!ctype_digit($id)) {
					throw new \InvalidArgumentException('Site IDs must be positive integers.');
				}
				$ids[] = $id;
			}
		}
		$ids = array_values(array_unique($ids));
		if ([] === $ids) {
			$currentSite = $this->siteRegistry::getCurrent();
			if (!$this->accessPolicy->canManageSite($user, $currentSite)) {
				throw new AccessDeniedHttpException('You cannot grant access to this site.');
			}

			return [$currentSite];
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

	private function normaliseTitle(string $title, string $fallback): string
	{
		$title = trim($title);
		if ('' === $title) {
			$title = trim($fallback);
		}
		$title = mb_substr($title, 0, 255);
		if ('' === $title) {
			throw new \InvalidArgumentException('Asset title is required.');
		}

		return $title;
	}

	private function validateExternalUri(string $uri): string
	{
		$uri = trim($uri);
		$parts = parse_url($uri);
		if (strlen($uri) > 2048 || false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || false === filter_var($uri, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('External assets must use a valid HTTPS URI without embedded credentials.');
		}

		return $uri;
	}

	private function normaliseLocalizedTextInput(mixed $value, string $fieldName): LocalizedValue|string|null
	{
		if (null === $value) {
			return null;
		}
		if (is_string($value)) {
			$trimmed = trim($value);

			return '' === $trimmed ? null : $trimmed;
		}
		if (is_array($value)) {
			if (LocalizedValue::isLocalizedArray($value)) {
				return LocalizedValue::fromArray($value);
			}
			if (array_key_exists('translations', $value) && 1 === count($value) && is_array($value['translations'])) {
				$value = $value['translations'];
			}
			if ([] === $value) {
				return null;
			}
			$translations = [];
			foreach ($value as $locale => $text) {
				if (!is_string($locale) || (!is_string($text) && null !== $text)) {
					throw new \InvalidArgumentException(sprintf('%s translations must map BCP 47 locale strings to text.', $fieldName));
				}
				if (null === $text) {
					continue;
				}
				$trimmed = trim($text);
				if ('' === $trimmed) {
					continue;
				}
				$translations[$locale] = $trimmed;
			}

			return [] === $translations ? null : LocalizedValue::fromTranslations($translations);
		}

		throw new \InvalidArgumentException(sprintf('%s must be a string, a locale map, or null.', $fieldName));
	}

	private function resetImageThumbnailWorkflowInstances(UploadedDigitalAsset $asset): void
	{
		$assetId = $asset->getId();
		if (null === $assetId) {
			return;
		}
		foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', $assetId) as $instance) {
			$processorKey = $instance->getDefinitionVersion()->getGraph()['metadata']['assetProcessor']['processorKey'] ?? null;
			if ('image_thumbnail' === $processorKey) {
				$this->entityManager->remove($instance);
			}
		}
	}

	private function resolveFolderForAdmin(int|string|null $folderId, User $user): ?DigitalAssetFolder
	{
		$folder = $this->findFolder($folderId);
		if (null === $folder) {
			return null;
		}
		if (
			!$this->accessPolicy->isGlobalAdmin($user)
			&& !$folder->isGlobalAccess()
			&& !$this->accessPolicy->canManageAllSites($user, $folder->getAccessSites()->toArray())
		) {
			throw new AccessDeniedHttpException('You cannot assign assets to a folder you do not administer.');
		}

		return $folder;
	}

	private function resolveFolderForSite(int|string|null $folderId, Site $site): ?DigitalAssetFolder
	{
		$folder = $this->findFolder($folderId);
		if (null === $folder) {
			return null;
		}
		if (!$folder->isAccessibleToSite($site)) {
			throw new AccessDeniedHttpException('The selected folder is not accessible to this site.');
		}

		return $folder;
	}

	private function findFolder(int|string|null $folderId): ?DigitalAssetFolder
	{
		if (null === $folderId) {
			return null;
		}
		$trimmed = trim((string) $folderId);
		if ('' === $trimmed) {
			return null;
		}
		if (!ctype_digit($trimmed) || '0' === $trimmed) {
			throw new \InvalidArgumentException('folderId must be a positive integer.');
		}

		$folder = null !== $this->folders
			? $this->folders->find($trimmed)
			: $this->entityManager->find(DigitalAssetFolder::class, $trimmed);
		if (!$folder instanceof DigitalAssetFolder) {
			throw new \InvalidArgumentException('Selected folder does not exist.');
		}

		return $folder;
	}

	/**
	 * @param list<mixed> $tagIds
	 *
	 * @return list<DigitalAssetTag>
	 */
	private function resolveTags(array $tagIds): array
	{
		if ([] === $tagIds) {
			return [];
		}

		$ids = [];
		foreach ($tagIds as $tagId) {
			if (!is_int($tagId) && !is_string($tagId)) {
				throw new \InvalidArgumentException('Tag IDs must be positive integers.');
			}
			$trimmed = trim((string) $tagId);
			if ('' === $trimmed) {
				continue;
			}
			if (!ctype_digit($trimmed) || '0' === $trimmed) {
				throw new \InvalidArgumentException('Tag IDs must be positive integers.');
			}
			$ids[] = $trimmed;
		}
		$ids = array_values(array_unique($ids));
		if ([] === $ids) {
			return [];
		}

		$found = null !== $this->tags
			? $this->tags->findByIds($ids)
			: $this->entityManager->getRepository(DigitalAssetTag::class)->findBy(['id' => $ids]);
		if (count($found) !== count($ids)) {
			throw new \InvalidArgumentException('One or more selected tags do not exist.');
		}

		return array_values($found);
	}

	/**
	 * @param array<string, mixed> $license
	 */
	private function applyLicensePayload(AbstractDigitalAsset $asset, array $license): void
	{
		if (array_key_exists('type', $license)) {
			$raw = $license['type'];
			if (null !== $raw && !is_string($raw)) {
				throw new \InvalidArgumentException('license.type must be a string or null.');
			}
			$asset->setLicenseType($raw);
		}
		if (array_key_exists('holder', $license)) {
			$raw = $license['holder'];
			if (null !== $raw && !is_string($raw)) {
				throw new \InvalidArgumentException('license.holder must be a string or null.');
			}
			$asset->setLicenseHolder($raw);
		}
		if (array_key_exists('url', $license)) {
			$raw = $license['url'];
			if (null !== $raw && !is_string($raw)) {
				throw new \InvalidArgumentException('license.url must be a string or null.');
			}
			$asset->setLicenseUrl($raw);
		}
		if (array_key_exists('notes', $license)) {
			$raw = $license['notes'];
			if (null !== $raw && !is_string($raw)) {
				throw new \InvalidArgumentException('license.notes must be a string or null.');
			}
			$asset->setLicenseNotes($raw);
		}
		if (array_key_exists('expiryAction', $license)) {
			$raw = $license['expiryAction'];
			if (null !== $raw && !is_string($raw)) {
				throw new \InvalidArgumentException('license.expiryAction must be "alert", "make_private", or null.');
			}
			$asset->setExpiryAction($raw);
		}
		if (array_key_exists('expiresAt', $license)) {
			$previousExpiresAt = $asset->getExpiresAt();
			$newExpiresAt = $this->parseLicenseExpiresAt($license['expiresAt']);
			$asset->setExpiresAt($newExpiresAt);

			$extended = null === $newExpiresAt
				|| null === $previousExpiresAt
				|| $newExpiresAt > $previousExpiresAt;
			if ($extended) {
				$asset->setExpiryNotifiedAt(null);
				$asset->setExpiredHandledAt(null);
			}
		}
	}

	private function parseLicenseExpiresAt(mixed $value): ?\DateTimeImmutable
	{
		if (null === $value || '' === $value) {
			return null;
		}
		if ($value instanceof \DateTimeImmutable) {
			return $value;
		}
		if (!is_string($value)) {
			throw new \InvalidArgumentException('license.expiresAt must be an ISO-8601 datetime string or null.');
		}
		$trimmed = trim($value);
		if ('' === $trimmed) {
			return null;
		}

		try {
			return new \DateTimeImmutable($trimmed);
		} catch (\Throwable $exception) {
			throw new \InvalidArgumentException('license.expiresAt must be a valid ISO-8601 date/time.', 0, $exception);
		}
	}
}
