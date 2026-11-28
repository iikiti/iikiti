<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\DigitalAssetVersion;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\DigitalAssetVersionRepository;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Manages append-only binary version history, replace-in-place, non-destructive
 * rollbacks, and reference-counted blob cleanup for uploaded digital assets.
 */
final class DigitalAssetVersionManager
{
	private readonly AssetMimeTypeRegistry $mimeRegistry;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetVersionRepository $versions,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly DigitalAssetAccessPolicy $accessPolicy,
		private readonly AssetStorageInterface $storage,
		private readonly MessageBusInterface $messageBus,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly AssetProcessingStateMachine $assetProcessingStateMachine,
		?AssetMimeTypeRegistry $mimeRegistry = null,
		private readonly ?AuditRecorder $auditRecorder = null,
	) {
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
	}

	public function recordInitialVersion(UploadedDigitalAsset $asset, ?User $creator = null): DigitalAssetVersion
	{
		$storageKey = $asset->getStorageKey();
		if (null === $storageKey || '' === $storageKey) {
			throw new \InvalidArgumentException('Cannot record initial version for an asset without a storage key.');
		}

		$version = new DigitalAssetVersion(
			asset: $asset,
			versionNumber: 1,
			storageKey: $storageKey,
			originalName: $asset->getOriginalName() ?? $asset->getTitle(),
			mimeType: $asset->getMimeType(),
			size: $asset->getSize() ?? 0,
			checksum: $asset->getChecksum() ?? '',
			width: $asset->getWidth(),
			height: $asset->getHeight(),
			durationMs: $asset->getDurationMs(),
			changeNote: null,
			createdById: $creator?->getId() ?? $asset->getCreatorId(),
			createdAt: new \DateTimeImmutable(),
		);
		$asset->setCurrentVersion(1);

		$this->entityManager->persist($version);
		$this->entityManager->flush();

		return $version;
	}

	/**
	 * Replaces the binary of an uploaded asset in place while preserving its
	 * database ID, public token, and all content references.
	 *
	 * @return array{asset: UploadedDigitalAsset, version: DigitalAssetVersion}
	 */
	public function replace(
		UploadedDigitalAsset $asset,
		UploadedFile $file,
		User $user,
		?string $changeNote = null,
		bool $allowMimeChange = false,
	): array {
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot replace assets shared with sites you do not administer.');
		}

		if (!$file->isValid()) {
			throw new \InvalidArgumentException('The replacement file is not valid.');
		}

		$mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
		if (!is_string($mimeType) || !$this->mimeRegistry->isAllowed($mimeType)) {
			throw new \InvalidArgumentException('This file type is not supported.');
		}
		$mimeType = strtolower(trim($mimeType));

		if (!$allowMimeChange && self::mimeFamily($asset->getMimeType()) !== self::mimeFamily($mimeType)) {
			throw new \InvalidArgumentException(sprintf(
				'Replacement MIME type "%s" does not match existing asset MIME family "%s". Set allowMimeChange=true to override.',
				$mimeType,
				self::mimeFamily($asset->getMimeType()),
			));
		}

		$size = $file->getSize();
		$maxBytes = $this->mimeRegistry->maxBytesFor($mimeType);
		if (false === $size || $size < 1 || $size > $maxBytes) {
			throw new \InvalidArgumentException(sprintf('The file must be between 1 byte and %d bytes.', $maxBytes));
		}

		$checksum = hash_file('sha256', $file->getPathname());
		if (!is_string($checksum)) {
			throw new \RuntimeException('Failed to compute SHA-256 checksum for replacement file.');
		}

		$newStorageKey = $this->storage->storeUploadedFile($file, $mimeType);
		$originalName = $file->getClientOriginalName();
		if ('' === trim($originalName)) {
			$originalName = $asset->getOriginalName() ?? $asset->getTitle();
		}

		$previousVersion = $asset->getCurrentVersion();
		$previousMime = $asset->getMimeType();
		$previousChecksum = $asset->getChecksum();
		$oldVariantKeys = [];

		try {
			$maxVersion = $this->ensureBaselineVersionAndGetMax($asset);
			$nextVersion = max($previousVersion, $maxVersion) + 1;

			$version = new DigitalAssetVersion(
				asset: $asset,
				versionNumber: $nextVersion,
				storageKey: $newStorageKey,
				originalName: $originalName,
				mimeType: $mimeType,
				size: $size,
				checksum: $checksum,
				width: null,
				height: null,
				durationMs: null,
				changeNote: $changeNote,
				createdById: $user->getId(),
				createdAt: new \DateTimeImmutable(),
			);

			$asset->setStorageKey($newStorageKey);
			$asset->setOriginalName($originalName);
			$asset->setMimeType($mimeType);
			$asset->setSize($size);
			$asset->setChecksum($checksum);
			$asset->setCurrentVersion($nextVersion);
			$asset->setWidth(null);
			$asset->setHeight(null);
			$asset->setDurationMs(null);
			$asset->setBlurhash(null);
			$asset->setDominantColor(null);
			$asset->setMediaMetadata(null);

			foreach ($this->variants->findByAsset($asset) as $variant) {
				$oldVariantKeys[] = $variant->getStorageKey();
				$this->entityManager->remove($variant);
			}

			$this->resetWorkflowInstances($asset);
			$this->assetProcessingStateMachine->requeue($asset);

			$this->entityManager->persist($version);
			$this->entityManager->flush();
		} catch (\Throwable $exception) {
			$this->storage->delete($newStorageKey);
			throw $exception;
		}

		$this->cleanupUnreferencedKeys($oldVariantKeys);

		$assetId = $asset->getId();
		if (null !== $assetId) {
			$this->messageBus->dispatch(new ProcessDigitalAsset($assetId));
		}
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::REPLACED);

		$this->auditRecorder?->record(
			sprintf('Replaced digital asset #%s binary (v%d -> v%d)', (string) ($assetId ?? ''), $previousVersion, $version->getVersionNumber()),
			'updated',
			'digital_asset',
			$assetId,
			['version' => $previousVersion, 'mimeType' => $previousMime, 'checksum' => $previousChecksum],
			['version' => $version->getVersionNumber(), 'mimeType' => $mimeType, 'checksum' => $checksum],
			['changeNote' => $version->getChangeNote(), 'allowMimeChange' => $allowMimeChange],
		);

		return ['asset' => $asset, 'version' => $version];
	}

	/**
	 * Performs a non-destructive append-only rollback by recording a new head
	 * version pointing to the target version's stored binary and metadata.
	 *
	 * @return array{asset: UploadedDigitalAsset, version: DigitalAssetVersion}
	 */
	public function rollback(
		UploadedDigitalAsset $asset,
		int $versionNumber,
		User $user,
		?string $changeNote = null,
	): array {
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot roll back assets shared with sites you do not administer.');
		}
		if ($versionNumber < 1) {
			throw new \InvalidArgumentException('Version number must be a positive integer.');
		}

		$target = $this->versions->findByAssetAndVersion($asset, $versionNumber);
		if (!$target instanceof DigitalAssetVersion) {
			throw new \InvalidArgumentException(sprintf('Version v%d was not found for this asset.', $versionNumber));
		}

		if (!$this->storage->exists($target->getStorageKey())) {
			throw new \RuntimeException(sprintf('The stored binary for version v%d is no longer available.', $versionNumber));
		}

		$previousVersion = $asset->getCurrentVersion();
		$maxVersion = $this->versions->findMaxVersionNumber($asset);
		$nextVersion = max($previousVersion, $maxVersion) + 1;
		$resolvedNote = null !== $changeNote && '' !== trim($changeNote)
			? trim($changeNote)
			: sprintf('Rolled back to v%d', $versionNumber);

		$newVersion = new DigitalAssetVersion(
			asset: $asset,
			versionNumber: $nextVersion,
			storageKey: $target->getStorageKey(),
			originalName: $target->getOriginalName(),
			mimeType: $target->getMimeType(),
			size: $target->getSize(),
			checksum: $target->getChecksum(),
			width: $target->getWidth(),
			height: $target->getHeight(),
			durationMs: $target->getDurationMs(),
			changeNote: $resolvedNote,
			createdById: $user->getId(),
			createdAt: new \DateTimeImmutable(),
		);

		$asset->setStorageKey($target->getStorageKey());
		$asset->setOriginalName($target->getOriginalName());
		$asset->setMimeType($target->getMimeType());
		$asset->setSize($target->getSize());
		$asset->setChecksum($target->getChecksum());
		$asset->setCurrentVersion($nextVersion);
		$asset->setWidth($target->getWidth());
		$asset->setHeight($target->getHeight());
		$asset->setDurationMs($target->getDurationMs());
		$asset->setBlurhash(null);
		$asset->setDominantColor(null);
		$asset->setMediaMetadata(null);

		$oldVariantKeys = [];
		foreach ($this->variants->findByAsset($asset) as $variant) {
			$oldVariantKeys[] = $variant->getStorageKey();
			$this->entityManager->remove($variant);
		}

		$this->resetWorkflowInstances($asset);
		$this->assetProcessingStateMachine->requeue($asset);

		$this->entityManager->persist($newVersion);
		$this->entityManager->flush();

		$this->cleanupUnreferencedKeys($oldVariantKeys);

		$assetId = $asset->getId();
		if (null !== $assetId) {
			$this->messageBus->dispatch(new ProcessDigitalAsset($assetId));
		}
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::REPLACED);

		$this->auditRecorder?->record(
			sprintf('Rolled back digital asset #%s from v%d to v%d (recorded as v%d)', (string) ($assetId ?? ''), $previousVersion, $versionNumber, $nextVersion),
			'updated',
			'digital_asset',
			$assetId,
			['version' => $previousVersion],
			['version' => $nextVersion, 'rolledBackTo' => $versionNumber],
			['changeNote' => $resolvedNote],
		);

		return ['asset' => $asset, 'version' => $newVersion];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listVersions(AbstractDigitalAsset $asset): array
	{
		$records = $this->versions->findByAsset($asset);
		$creatorLabels = $this->resolveCreatorLabels($records);

		$out = [];
		foreach ($records as $version) {
			$creatorId = $version->getCreatedById();
			$label = null !== $creatorId ? ($creatorLabels[(string) $creatorId] ?? null) : null;
			$out[] = $this->serializeVersion($version, $asset, $label);
		}

		return $out;
	}

	public function findVersion(AbstractDigitalAsset $asset, int $versionNumber): ?DigitalAssetVersion
	{
		return $this->versions->findByAssetAndVersion($asset, $versionNumber);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function serializeVersion(
		DigitalAssetVersion $version,
		AbstractDigitalAsset $asset,
		?string $createdByLabel = null,
	): array {
		$assetId = $asset->getId();
		$downloadUrl = null !== $assetId
			? sprintf('/api/admin/assets/%s/versions/%d/download', (string) $assetId, $version->getVersionNumber())
			: null;

		return [
			'versionNumber' => $version->getVersionNumber(),
			'mimeType' => $version->getMimeType(),
			'originalName' => $version->getOriginalName(),
			'size' => $version->getSize(),
			'checksum' => $version->getChecksum(),
			'width' => $version->getWidth(),
			'height' => $version->getHeight(),
			'durationMs' => $version->getDurationMs(),
			'changeNote' => $version->getChangeNote(),
			'createdById' => $version->getCreatedById(),
			'createdBy' => $createdByLabel,
			'createdAt' => $version->getCreatedAt()->format(\DateTimeInterface::ATOM),
			'isCurrent' => $version->getVersionNumber() === $asset->getCurrentVersion(),
			'downloadUrl' => $downloadUrl,
		];
	}

	/**
	 * Returns true if any version row, asset head pointer, or variant still
	 * references `$storageKey` (optionally excluding an asset currently being deleted).
	 */
	public function isStorageKeyReferenced(string $storageKey, int|string|null $excludeAssetId = null): bool
	{
		if ('' === trim($storageKey)) {
			return false;
		}

		if ($this->versions->countStorageKeyReferences($storageKey, $excludeAssetId) > 0) {
			return true;
		}

		$assetQb = $this->entityManager->createQueryBuilder()->
			select('COUNT(a.id)')->
			from(AbstractDigitalAsset::class, 'a')->
			andWhere('a.storageKey = :storageKey')->
			setParameter('storageKey', $storageKey);
		if (null !== $excludeAssetId) {
			$assetQb->andWhere('a.id != :excludeAssetId')->
				setParameter('excludeAssetId', $excludeAssetId);
		}
		if ((int) $assetQb->getQuery()->getSingleScalarResult() > 0) {
			return true;
		}

		$variantQb = $this->entityManager->createQueryBuilder()->
			select('COUNT(v.id)')->
			from(DigitalAssetVariant::class, 'v')->
			andWhere('v.storageKey = :storageKey')->
			setParameter('storageKey', $storageKey);
		if (null !== $excludeAssetId) {
			$variantQb->andWhere('IDENTITY(v.asset) != :excludeAssetId')->
				setParameter('excludeAssetId', $excludeAssetId);
		}

		return (int) $variantQb->getQuery()->getSingleScalarResult() > 0;
	}

	/**
	 * Collects every storage key referenced by historical version rows for `$asset`.
	 *
	 * @return list<string>
	 */
	public function collectVersionStorageKeys(AbstractDigitalAsset $asset): array
	{
		$keys = [];
		foreach ($this->versions->findByAsset($asset) as $version) {
			$keys[] = $version->getStorageKey();
		}

		return array_values(array_unique($keys));
	}

	public static function mimeFamily(string $mimeType): string
	{
		$normalized = strtolower(trim($mimeType));
		foreach (['image/', 'video/', 'audio/', 'text/'] as $prefix) {
			if (str_starts_with($normalized, $prefix)) {
				return $prefix.'*';
			}
		}

		return $normalized;
	}

	private function ensureBaselineVersionAndGetMax(UploadedDigitalAsset $asset): int
	{
		$max = $this->versions->findMaxVersionNumber($asset);
		if ($max > 0) {
			return $max;
		}

		$existingKey = $asset->getStorageKey();
		if (null !== $existingKey && '' !== $existingKey) {
			$baseline = new DigitalAssetVersion(
				asset: $asset,
				versionNumber: 1,
				storageKey: $existingKey,
				originalName: $asset->getOriginalName() ?? $asset->getTitle(),
				mimeType: $asset->getMimeType(),
				size: $asset->getSize() ?? 0,
				checksum: $asset->getChecksum() ?? '',
				width: $asset->getWidth(),
				height: $asset->getHeight(),
				durationMs: $asset->getDurationMs(),
				changeNote: null,
				createdById: $asset->getCreatorId(),
				createdAt: $asset->getCreatedDate() instanceof \DateTimeImmutable
					? $asset->getCreatedDate()
					: new \DateTimeImmutable(),
			);
			$this->entityManager->persist($baseline);

			return 1;
		}

		return 0;
	}

	private function resetWorkflowInstances(UploadedDigitalAsset $asset): void
	{
		$assetId = $asset->getId();
		if (null === $assetId) {
			return;
		}
		foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', $assetId) as $instance) {
			$this->entityManager->remove($instance);
		}
	}

	/**
	 * @param list<string> $keys
	 */
	private function cleanupUnreferencedKeys(array $keys): void
	{
		foreach (array_values(array_unique($keys)) as $key) {
			if (!$this->isStorageKeyReferenced($key)) {
				$this->storage->delete($key);
			}
		}
	}

	/**
	 * @param list<DigitalAssetVersion> $records
	 *
	 * @return array<string, string>
	 */
	private function resolveCreatorLabels(array $records): array
	{
		$labels = [];
		$checked = [];
		foreach ($records as $version) {
			$creatorId = $version->getCreatedById();
			if (null === $creatorId) {
				continue;
			}
			$key = (string) $creatorId;
			if (isset($checked[$key])) {
				continue;
			}
			$checked[$key] = true;

			$user = $this->entityManager->find(User::class, $creatorId);
			if (!$user instanceof User) {
				continue;
			}
			$props = $user->getProperties();
			$name = $props->get('name')?->getValue()
				?? $props->get('username')?->getValue()
				?? ($user->getEmails()[0] ?? null);
			if (is_string($name) && '' !== trim($name)) {
				$labels[$key] = trim($name);
			}
		}

		return $labels;
	}
}
