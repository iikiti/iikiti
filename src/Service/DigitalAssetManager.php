<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetManager
{
	private const MAX_UPLOAD_BYTES = 26214400;

	private const MIME_TYPES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp',
		'application/pdf', 'text/plain', 'text/markdown', 'text/csv',
		'application/json', 'application/xml', 'text/xml',
	];

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly SiteRepository $sites,
		private readonly SiteRegistry $siteRegistry,
		private readonly DigitalAssetAccessPolicy $accessPolicy,
		private readonly AssetStorage $storage,
		private readonly MessageBusInterface $messageBus,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly AssetProcessingStateMachine $assetProcessingStateMachine,
	) {
	}

	/** @param list<mixed> $siteIds */
	public function createUpload(UploadedFile $file, string $title, string $visibility, array $siteIds, User $creator): AbstractDigitalAsset
	{
		if (!$file->isValid()) {
			throw new \InvalidArgumentException('The uploaded file is not valid.');
		}
		$size = $file->getSize();
		if (false === $size || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
			throw new \InvalidArgumentException('The file must be between 1 byte and 25 MB.');
		}

		$mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
		if (!is_string($mimeType) || !in_array($mimeType, self::MIME_TYPES, true)) {
			throw new \InvalidArgumentException('This file type is not supported.');
		}

		$sites = $this->resolveSites($siteIds, $creator);
		$asset = new UploadedDigitalAsset($this->normaliseTitle($title, $file->getClientOriginalName()), $mimeType, bin2hex(random_bytes(32)));
		$asset->setSite($this->siteRegistry::getCurrent());
		$asset->setCreatorId($this->creatorId($creator));
		$asset->setCreatedDate(new \DateTimeImmutable());
		$asset->setAccessSites($sites);
		$asset->setVisibility($visibility);
		$asset->setOriginalName($file->getClientOriginalName());
		$asset->setSize($size);

		$storageKey = $this->storage->storeUploadedFile($file, $mimeType);
		$asset->setStorageKey($storageKey);
		$checksum = hash_file('sha256', $this->storage->pathFor($storageKey));
		$asset->setChecksum(is_string($checksum) ? $checksum : null);

		try {
			$this->entityManager->persist($asset);
			$this->entityManager->flush();
		} catch (\Throwable $exception) {
			$this->storage->delete($storageKey);
			throw $exception;
		}

		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::CREATED);
		$this->messageBus->dispatch(new ProcessDigitalAsset($this->persistedId($asset)));

		return $asset;
	}

	/** @param list<mixed> $siteIds */
	public function createExternal(string $uri, string $title, string $visibility, array $siteIds, User $creator, ?string $mimeType = null): ExternalDigitalAsset
	{
		$uri = $this->validateExternalUri($uri);
		$mimeType = null === $mimeType || '' === trim($mimeType) ? 'application/octet-stream' : strtolower(trim($mimeType));
		if (strlen($mimeType) > 127 || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $mimeType)) {
			throw new \InvalidArgumentException('Invalid MIME type.');
		}

		$asset = new ExternalDigitalAsset($this->normaliseTitle($title, $uri), $mimeType, bin2hex(random_bytes(32)));
		$asset->setSite($this->siteRegistry::getCurrent());
		$asset->setCreatorId($this->creatorId($creator));
		$asset->setCreatedDate(new \DateTimeImmutable());
		$asset->setAccessSites($this->resolveSites($siteIds, $creator));
		$asset->setVisibility($visibility);
		$asset->setExternalUri($uri);
		$asset->setProcessingStatus('complete');
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
		if (array_key_exists('uri', $payload)) {
			if (!$asset instanceof ExternalDigitalAsset) {
				throw new \InvalidArgumentException('Only external assets can change their URI.');
			}
			$asset->setExternalUri($this->validateExternalUri((string) $payload['uri']));
		}

		$this->entityManager->flush();
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::UPDATED);

		return $asset;
	}

	public function delete(AbstractDigitalAsset $asset, User $user): void
	{
		if (!$this->accessPolicy->canManageAsset($user, $asset)) {
			throw new AccessDeniedHttpException('You cannot manage assets shared with sites you do not administer.');
		}
		$keys = [];
		if (null !== $asset->getStorageKey()) {
			$keys[] = $asset->getStorageKey();
		}
		foreach ($this->variants->findByAsset($asset) as $variant) {
			$keys[] = $variant->getStorageKey();
			$this->entityManager->remove($variant);
		}
		$assetId = $asset->getId();
		if (null !== $assetId) {
			foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', $assetId) as $instance) {
				$this->entityManager->remove($instance);
			}
		}
		$this->entityManager->remove($asset);
		$this->entityManager->flush();
		foreach (array_unique($keys) as $key) {
			$this->storage->delete($key);
		}
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::DELETED);
	}

	public function queueProcessing(AbstractDigitalAsset $asset): void
	{
		if ($asset instanceof UploadedDigitalAsset) {
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
}
