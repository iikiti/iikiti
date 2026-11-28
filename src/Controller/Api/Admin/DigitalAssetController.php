<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageReference;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\DigitalAssetVersion;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Exception\AssetInUseException;
use iikiti\CMS\Exception\DuplicateAssetException;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetFilterCriteria;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\DigitalAssetVersionRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\AssetSignedUrlService;
use iikiti\CMS\Service\AssetStreamResponseFactory;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetBulkActionService;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\DigitalAssetSerializer;
use iikiti\CMS\Service\DigitalAssetVersionManager;
use iikiti\CMS\Value\LocaleTag;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/assets', name: 'api_admin_digital_assets_')]
final class DigitalAssetController extends AppController
{
	public function __construct(
		Security $security,
		ContainerInterface $container,
		#[Autowire('%asset_expiry_warning_days%')]
		private readonly int $expiryWarningDays = 14,
	) {
		parent::__construct($security, $container);
	}

	#[Route('', name: 'index', methods: ['GET'])]
	public function index(
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		try {
			$requestedLocale = $this->requestedLocale($request);
			$criteria = DigitalAssetFilterCriteria::fromRequest($request, 25, 100);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$isGlobalAdmin = $accessPolicy->isGlobalAdmin($user);
		$manageableSiteIds = $isGlobalAdmin ? [] : $accessPolicy->getManageableSiteIds($user);

		$records = $assets->findManageableFiltered($manageableSiteIds, $isGlobalAdmin, $criteria);
		$totalItems = $assets->countManageableFiltered($manageableSiteIds, $isGlobalAdmin, $criteria);
		$facets = $assets->computeFacets($manageableSiteIds, $isGlobalAdmin, $criteria);
		$page = (int) floor($criteria->offset / $criteria->limit) + 1;

		return $this->json([
			'assets' => $serializer->serializeMany($records, $requestedLocale, true),
			'totalItems' => $totalItems,
			'page' => $page,
			'itemsPerPage' => $criteria->limit,
			'facets' => $facets,
		]);
	}

	#[Route('/bulk', name: 'bulk', methods: ['POST'])]
	public function bulk(
		Request $request,
		DigitalAssetBulkActionService $bulkActions,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		$action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : '';
		$assetIds = $payload['assetIds'] ?? null;
		if (!is_array($assetIds)) {
			return $this->json(['error' => 'assetIds must be a list of asset IDs.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
		foreach (['folderId', 'tagIds', 'tagNames', 'namespace', 'collectionId', 'visibility', 'siteIds', 'force'] as $key) {
			if (!array_key_exists($key, $params) && array_key_exists($key, $payload)) {
				$params[$key] = $payload[$key];
			}
		}

		try {
			$result = $bulkActions->execute($action, array_values($assetIds), $params, $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		return $this->json($result);
	}

	#[Route('/sites', name: 'sites', methods: ['GET'])]
	public function sites(
		DigitalAssetAccessPolicy $accessPolicy,
		SiteRegistry $siteRegistry,
		?SiteRepository $siteRepository = null,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$records = $accessPolicy->getManageableSites($user);
		$currentSite = $siteRegistry::getCurrent();
		$currentLocales = $this->resolveSiteLocales($currentSite, $siteRepository);

		return $this->json([
			'sites' => array_map(function (Site $site) use ($siteRepository): array {
				$properties = $site->getProperties();
				$locales = $this->resolveSiteLocales($site, $siteRepository);

				return [
					'id' => $site->getId(),
					'domain' => $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? '',
					'name' => $properties->get('name')?->getValue() ?? $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site',
					'defaultLocale' => $locales['defaultLocale'],
					'supportedContentLocales' => $locales['supportedContentLocales'],
				];
			}, $records),
			'defaultSiteId' => $currentSite->getId(),
			'defaultLocale' => $currentLocales['defaultLocale'],
			'supportedContentLocales' => $currentLocales['supportedContentLocales'],
			'expiryWarningDays' => max(1, $this->expiryWarningDays),
		]);
	}

	#[Route('/check-duplicate', name: 'check_duplicate', methods: ['POST'])]
	public function checkDuplicate(
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		$checksum = isset($payload['checksum']) && is_string($payload['checksum'])
			? strtolower(trim($payload['checksum']))
			: '';
		if (1 !== preg_match('/^[a-f0-9]{64}$/', $checksum)) {
			return $this->json(['error' => 'checksum must be a valid 64-character SHA-256 hex digest.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		try {
			$requestedSiteIds = array_map(
				static fn (mixed $id): string => trim((string) $id),
				$this->siteIds($payload['siteIds'] ?? []),
			);
			$requestedSiteIds = array_values(array_unique(array_filter(
				$requestedSiteIds,
				static fn (string $id): bool => '' !== $id && ctype_digit($id),
			)));
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$isGlobalAdmin = $accessPolicy->isGlobalAdmin($user);
		if ($isGlobalAdmin) {
			$scopeSiteIds = $requestedSiteIds;
		} else {
			$manageableSiteIds = array_map(
				static fn (int|string $id): string => (string) $id,
				$accessPolicy->getManageableSiteIds($user),
			);
			$scopeSiteIds = [] !== $requestedSiteIds
				? array_values(array_intersect($requestedSiteIds, $manageableSiteIds))
				: $manageableSiteIds;
		}

		$matches = $assets->findByChecksumForSites($checksum, $scopeSiteIds, $isGlobalAdmin);
		if (!$isGlobalAdmin) {
			$matches = array_values(array_filter(
				$matches,
				static fn (AbstractDigitalAsset $match): bool => $accessPolicy->canManageAsset($user, $match),
			));
		}

		return $this->json([
			'duplicate' => [] !== $matches,
			'matches' => $serializer->serializeMany($matches, null, false),
		]);
	}

	#[Route('/upload', name: 'upload', methods: ['POST'])]
	public function upload(
		Request $request,
		DigitalAssetManager $manager,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		if (!$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$user = $this->getUser();
		$file = $request->files->get('file');
		if (!$user instanceof User || !$file instanceof UploadedFile) {
			return $this->json(['error' => 'An authenticated admin and uploaded file are required.'], Response::HTTP_BAD_REQUEST);
		}
		try {
			$rawFolderId = $request->request->get('folderId');
			$folderId = is_string($rawFolderId) && '' !== trim($rawFolderId) ? trim($rawFolderId) : null;
			$duplicatePolicy = strtolower(trim((string) $request->request->get('duplicatePolicy', DigitalAssetManager::DUPLICATE_POLICY_ALLOW)));
			if ('' === $duplicatePolicy) {
				$duplicatePolicy = DigitalAssetManager::DUPLICATE_POLICY_ALLOW;
			}
			$license = null;
			$rawLicense = $request->request->get('license');
			if (is_string($rawLicense) && '' !== trim($rawLicense)) {
				$decodedLicense = json_decode($rawLicense, true);
				if (!is_array($decodedLicense)) {
					throw new \InvalidArgumentException('license must be a valid JSON object.');
				}
				$license = $decodedLicense;
			}
			$requestParams = $request->request->all();
			$asset = $manager->createUpload(
				$file,
				(string) $request->request->get('title', ''),
				(string) $request->request->get('visibility', 'private'),
				$this->siteIds($requestParams['siteIds'] ?? ''),
				$user,
				$folderId,
				$this->idList($requestParams['tagIds'] ?? '', 'tagIds'),
				$duplicatePolicy,
				$license,
			);
		} catch (DuplicateAssetException $exception) {
			return $this->json([
				'error' => $exception->getMessage(),
				'duplicate' => true,
				'matches' => $serializer->serializeMany($exception->getMatches(), null, false),
			], Response::HTTP_CONFLICT);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		if (DigitalAssetManager::DUPLICATE_POLICY_REUSE === $duplicatePolicy) {
			$payload = $serializer->serialize($asset, null, true, null, null);
		} else {
			$payload = $serializer->serialize($asset, null, false, [], 0);
			$payload['workflowInstances'] = [];
		}

		return $this->json($payload, Response::HTTP_CREATED);
	}

	#[Route('/uri', name: 'create_uri', methods: ['POST'])]
	public function createUri(
		Request $request,
		DigitalAssetManager $manager,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		if (!$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$user = $this->getUser();
		if (!$user instanceof User) {
			return $this->forbidden();
		}
		$payload = $this->payload($request);
		try {
			$folderId = null;
			if (isset($payload['folderId']) && (is_int($payload['folderId']) || is_string($payload['folderId']))) {
				$folderId = $payload['folderId'];
			}
			$license = isset($payload['license']) && is_array($payload['license']) ? $payload['license'] : null;
			$asset = $manager->createExternal(
				(string) ($payload['uri'] ?? ''),
				(string) ($payload['title'] ?? ''),
				(string) ($payload['visibility'] ?? 'private'),
				$this->siteIds($payload['siteIds'] ?? []),
				$user,
				isset($payload['mimeType']) ? (string) $payload['mimeType'] : null,
				$folderId,
				$this->idList($payload['tagIds'] ?? [], 'tagIds'),
				$license,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		$serialized = $serializer->serialize($asset, null, false, [], 0);
		$serialized['workflowInstances'] = [];

		return $this->json($serialized, Response::HTTP_CREATED);
	}

	#[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function show(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		try {
			$requestedLocale = $this->requestedLocale($request);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json($serializer->serialize($asset, $requestedLocale, true));
	}

	#[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function update(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetManager $manager,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		try {
			$requestedLocale = $this->requestedLocale($request);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		try {
			$asset = $manager->update($asset, $this->payload($request), $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		return $this->json($serializer->serialize($asset, $requestedLocale, true));
	}

	#[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function delete(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetManager $manager,
		DigitalAssetAccessPolicy $accessPolicy,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$force = $this->parseBool($request->query->get('force', false));
		try {
			$manager->delete($asset, $user, $force);
		} catch (AssetInUseException $exception) {
			return $this->json([
				'error' => $exception->getMessage(),
				'usageCount' => $exception->getUsageCount(),
				'usages' => $exception->getUsages(),
			], Response::HTTP_CONFLICT);
		}

		return $this->json(['deleted' => true]);
	}

	#[Route('/{id}/usage', name: 'usage', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function usage(
		int $id,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		AssetUsageIndexInterface $usageIndex,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$references = $usageIndex->findUsage($id);

		return $this->json([
			'assetId' => $id,
			'usageCount' => count($references),
			'usages' => array_map(static fn (AssetUsageReference $ref): array => $ref->toArray(), $references),
		]);
	}

	#[Route('/{id}/versions', name: 'versions', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function versions(
		int $id,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetVersionManager $versionManager,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json([
			'currentVersion' => $asset->getCurrentVersion(),
			'versions' => $versionManager->listVersions($asset),
		]);
	}

	#[Route('/{id}/replace', name: 'replace', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function replace(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetVersionManager $versionManager,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		if (!$asset instanceof UploadedDigitalAsset) {
			return $this->json(['error' => 'Only uploaded assets can be replaced in place.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$file = $request->files->get('file');
		if (!$file instanceof UploadedFile) {
			return $this->json(['error' => 'A replacement file is required.'], Response::HTTP_BAD_REQUEST);
		}

		$rawChangeNote = $request->request->get('changeNote');
		$changeNote = is_string($rawChangeNote) && '' !== trim($rawChangeNote) ? trim($rawChangeNote) : null;
		$allowMimeChange = $this->parseBool($request->request->get('allowMimeChange', false));

		try {
			$result = $versionManager->replace($asset, $file, $user, $changeNote, $allowMimeChange);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		$serializedAsset = $serializer->serialize($result['asset'], null, true, []);
		$serializedVersion = $versionManager->serializeVersion($result['version'], $result['asset']);

		return $this->json(array_merge($serializedAsset, [
			'asset' => $serializedAsset,
			'version' => $serializedVersion,
		]));
	}

	#[Route('/{id}/versions/{versionNumber}/rollback', name: 'version_rollback', methods: ['POST'], requirements: ['id' => '\\d+', 'versionNumber' => '\\d+'])]
	public function rollbackVersion(
		int $id,
		int $versionNumber,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetVersionManager $versionManager,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		if (!$asset instanceof UploadedDigitalAsset) {
			return $this->json(['error' => 'Only uploaded assets support version rollback.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$payload = $this->payload($request);
		$changeNote = isset($payload['changeNote']) && is_string($payload['changeNote']) && '' !== trim($payload['changeNote'])
			? trim($payload['changeNote'])
			: null;

		try {
			$result = $versionManager->rollback($asset, $versionNumber, $user, $changeNote);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
		} catch (\RuntimeException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		$serializedAsset = $serializer->serialize($result['asset'], null, true, []);
		$serializedVersion = $versionManager->serializeVersion($result['version'], $result['asset']);

		return $this->json(array_merge($serializedAsset, [
			'asset' => $serializedAsset,
			'version' => $serializedVersion,
		]));
	}

	#[Route('/{id}/versions/{versionNumber}/download', name: 'version_download', methods: ['GET'], requirements: ['id' => '\\d+', 'versionNumber' => '\\d+'])]
	public function downloadVersion(
		int $id,
		int $versionNumber,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetVersionManager $versionManager,
		AssetStreamResponseFactory $responseFactory,
	): Response {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		if (!$asset instanceof UploadedDigitalAsset) {
			return $this->json(['error' => 'Only uploaded assets have downloadable versions.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$version = $versionManager->findVersion($asset, $versionNumber);
		if (!$version instanceof DigitalAssetVersion) {
			return $this->json(['error' => 'Asset version not found.'], Response::HTTP_NOT_FOUND);
		}

		return $responseFactory->createResponse(
			$request,
			$version->getStorageKey(),
			$version->getMimeType(),
			$version->getOriginalName(),
			'private',
			$version->getChecksum(),
			(int) $version->getSize(),
			ResponseHeaderBag::DISPOSITION_ATTACHMENT,
			true,
		);
	}

	#[Route('/{id}/reprocess', name: 'reprocess', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function reprocess(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetManager $manager,
		DigitalAssetAccessPolicy $accessPolicy,
		DigitalAssetSerializer $serializer,
		?AuditRecorder $auditRecorder = null,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		try {
			$requestedLocale = $this->requestedLocale($request);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		if (!$asset instanceof UploadedDigitalAsset) {
			return $this->json(['error' => 'Only uploaded assets can be reprocessed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$manager->queueProcessing($asset, resetCompletedWorkflows: true);

		$auditRecorder?->record(
			sprintf('Queued reprocessing for digital asset #%s', (string) $asset->getId()),
			'updated',
			'digital_asset',
			$asset->getId(),
		);

		$serialized = $serializer->serialize($asset, $requestedLocale, true);
		$serialized['queued'] = true;

		return $this->json($serialized);
	}

	#[Route('/{id}/signed-url', name: 'signed_url', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function signedUrl(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		DigitalAssetAccessPolicy $accessPolicy,
		SiteRepository $sites,
		SiteRegistry $siteRegistry,
		AssetSignedUrlService $signedUrls,
		DigitalAssetVersionRepository $versions,
		?AuditRecorder $auditRecorder = null,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$payload = $this->payload($request);
		$variant = isset($payload['variant']) && is_string($payload['variant']) && '' !== trim($payload['variant']) ?
			trim($payload['variant']) :
			'original';
		$disposition = isset($payload['disposition']) && is_string($payload['disposition']) && '' !== trim($payload['disposition']) ?
			trim($payload['disposition']) :
			'inline';
		$ttlRaw = $payload['ttlSeconds'] ?? AssetSignedUrlService::DEFAULT_TTL_SECONDS;
		$ttlSeconds = filter_var($ttlRaw, FILTER_VALIDATE_INT);
		if (false === $ttlSeconds) {
			return $this->json(['error' => 'ttlSeconds must be an integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$version = null;
		if (array_key_exists('version', $payload) && null !== $payload['version'] && '' !== $payload['version']) {
			$parsedVersion = filter_var($payload['version'], FILTER_VALIDATE_INT);
			if (false === $parsedVersion || $parsedVersion < 1) {
				return $this->json(['error' => 'version must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
			}
			$version = $parsedVersion;
		}

		if (array_key_exists('siteId', $payload) && null !== $payload['siteId'] && '' !== $payload['siteId']) {
			if (!is_int($payload['siteId']) && !(is_string($payload['siteId']) && ctype_digit($payload['siteId']))) {
				return $this->json(['error' => 'siteId must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
			}
			$site = $sites->find((string) $payload['siteId']);
		} else {
			$site = $siteRegistry::getCurrent();
		}

		if (!$site instanceof Site || !$accessPolicy->canManageSite($user, $site) || !$asset->grantsAccessTo($site)) {
			return $this->json(['error' => 'Asset is not granted to the requested site.'], Response::HTTP_FORBIDDEN);
		}

		if (null !== $version) {
			if ('original' !== $variant && $version !== $asset->getCurrentVersion()) {
				return $this->json(['error' => 'Only the "original" variant can be signed for historical asset versions.'], Response::HTTP_UNPROCESSABLE_ENTITY);
			}
			if (!$versions->findByAssetAndVersion($asset, $version) instanceof DigitalAssetVersion) {
				return $this->json(['error' => 'Asset version not found.'], Response::HTTP_NOT_FOUND);
			}
		}

		if ('original' !== $variant && !$variants->findByAssetAndKey($asset, $variant) instanceof DigitalAssetVariant) {
			return $this->json(['error' => 'Asset variant not found.'], Response::HTTP_NOT_FOUND);
		}

		try {
			$signed = $signedUrls->generate($asset, $site, $variant, $ttlSeconds, $disposition, $version);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$auditRecorder?->record(
			sprintf('Generated signed URL for digital asset #%s (%s)', (string) $asset->getId(), $variant),
			'created',
			'digital_asset_signed_url',
			$asset->getId(),
			null,
			null,
			[
				'variant' => $variant,
				'siteId' => (string) $site->getId(),
				'disposition' => $disposition,
				'ttlSeconds' => $ttlSeconds,
				'expiresAt' => $signed['expiresAt'],
			],
		);

		return $this->json($signed, Response::HTTP_CREATED);
	}

	/**
	 * @return array{defaultLocale: ?string, supportedContentLocales: list<string>}
	 */
	private function resolveSiteLocales(Site $site, ?SiteRepository $siteRepository): array
	{
		$application = $siteRepository?->getApplicationBySite($site);
		$defaultLocale = $site->getDefaultLocale() ?? $application?->getDefaultLocale();
		$supported = $site->getSupportedContentLocales() ?? $application?->getSupportedContentLocales();

		return [
			'defaultLocale' => $defaultLocale?->getTag(),
			'supportedContentLocales' => null !== $supported
				? array_map(static fn (LocaleTag $locale): string => $locale->getTag(), $supported)
				: [],
		];
	}

	private function requestedLocale(Request $request): ?LocaleTag
	{
		$locale = $request->query->get('locale');

		return is_string($locale) ? LocaleTag::tryFromString($locale) : null;
	}

	/** @return list<mixed> */
	private function siteIds(mixed $value): array
	{
		if (is_string($value)) {
			if ('' === trim($value)) {
				return [];
			}
			$value = json_decode($value, true);
		}
		if (!is_array($value)) {
			throw new \InvalidArgumentException('Site grants must be a list of site IDs.');
		}

		return array_values($value);
	}

	/** @return list<mixed> */
	private function idList(mixed $value, string $fieldName): array
	{
		if (null === $value || '' === $value) {
			return [];
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' === $trimmed) {
				return [];
			}
			if (str_starts_with($trimmed, '[')) {
				$decoded = json_decode($trimmed, true);
				if (!is_array($decoded)) {
					throw new \InvalidArgumentException(sprintf('%s must be a list of IDs.', $fieldName));
				}

				return array_values($decoded);
			}

			return array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn (string $v): bool => '' !== $v));
		}
		if (!is_array($value)) {
			throw new \InvalidArgumentException(sprintf('%s must be a list of IDs.', $fieldName));
		}

		return array_values($value);
	}

	/** @return array<string,mixed> */
	private function payload(Request $request): array
	{
		$payload = json_decode((string) $request->getContent(), true);

		return is_array($payload) ? $payload : [];
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
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
