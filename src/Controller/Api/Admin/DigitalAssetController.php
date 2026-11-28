<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\AssetSignedUrlService;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/assets', name: 'api_admin_digital_assets_')]
final class DigitalAssetController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(
		Request $request,
		DigitalAssetRepository $assets,
		EntityManagerInterface $entityManager,
		DigitalAssetVariantRepository $variants,
		DigitalAssetAccessPolicy $accessPolicy,
		?LocalizedPropertyResolver $localizedProperties = null,
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
		$page = max(1, $request->query->getInt('page', 1));
		$perPage = min(100, max(1, $request->query->getInt('itemsPerPage', 25)));
		if ($accessPolicy->isGlobalAdmin($user)) {
			$records = $assets->findBy([], ['id' => 'DESC'], $perPage, ($page - 1) * $perPage);
			$totalItems = $assets->count([]);
		} else {
			$siteIds = $accessPolicy->getManageableSiteIds($user);
			$records = $assets->findManageableBySites($siteIds, $perPage, ($page - 1) * $perPage);
			$totalItems = $assets->countManageableBySites($siteIds);
		}

		return $this->json([
			'assets' => array_map(
				fn (AbstractDigitalAsset $asset): array => $this->serialise($asset, $entityManager, $variants, $localizedProperties, $requestedLocale),
				$records,
			),
			'totalItems' => $totalItems,
			'page' => $page,
			'itemsPerPage' => $perPage,
		]);
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
		]);
	}

	#[Route('/upload', name: 'upload', methods: ['POST'])]
	public function upload(
		Request $request,
		DigitalAssetManager $manager,
		?LocalizedPropertyResolver $localizedProperties = null,
	): JsonResponse {
		if (!$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$user = $this->getUser();
		$file = $request->files->get('file');
		if (!$user instanceof User || !$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
			return $this->json(['error' => 'An authenticated admin and uploaded file are required.'], Response::HTTP_BAD_REQUEST);
		}
		try {
			$asset = $manager->createUpload(
				$file,
				(string) $request->request->get('title', ''),
				(string) $request->request->get('visibility', 'private'),
				$this->siteIds($request->request->get('siteIds', '')),
				$user,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($this->serialise($asset, null, null, $localizedProperties), Response::HTTP_CREATED);
	}

	#[Route('/uri', name: 'create_uri', methods: ['POST'])]
	public function createUri(
		Request $request,
		DigitalAssetManager $manager,
		?LocalizedPropertyResolver $localizedProperties = null,
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
			$asset = $manager->createExternal(
				(string) ($payload['uri'] ?? ''),
				(string) ($payload['title'] ?? ''),
				(string) ($payload['visibility'] ?? 'private'),
				$this->siteIds($payload['siteIds'] ?? []),
				$user,
				isset($payload['mimeType']) ? (string) $payload['mimeType'] : null,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($this->serialise($asset, null, null, $localizedProperties), Response::HTTP_CREATED);
	}

	#[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function show(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		EntityManagerInterface $entityManager,
		DigitalAssetVariantRepository $variants,
		DigitalAssetAccessPolicy $accessPolicy,
		?LocalizedPropertyResolver $localizedProperties = null,
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

		return $this->json($this->serialise($asset, $entityManager, $variants, $localizedProperties, $requestedLocale));
	}

	#[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function update(
		int $id,
		Request $request,
		DigitalAssetRepository $assets,
		DigitalAssetManager $manager,
		EntityManagerInterface $entityManager,
		DigitalAssetVariantRepository $variants,
		DigitalAssetAccessPolicy $accessPolicy,
		?LocalizedPropertyResolver $localizedProperties = null,
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

		return $this->json($this->serialise($asset, $entityManager, $variants, $localizedProperties, $requestedLocale));
	}

	#[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function delete(int $id, DigitalAssetRepository $assets, DigitalAssetManager $manager, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		$manager->delete($asset, $user);

		return $this->json(['deleted' => true]);
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

		if (array_key_exists('siteId', $payload) && null !== $payload['siteId'] && '' !== (string) $payload['siteId']) {
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

	/** @return array<string,mixed> */
	private function serialise(
		AbstractDigitalAsset $asset,
		?EntityManagerInterface $entityManager,
		?DigitalAssetVariantRepository $variants,
		?LocalizedPropertyResolver $localizedProperties = null,
		?LocaleTag $requestedLocale = null,
	): array {
		$siteIds = [];
		foreach ($asset->getAccessSites() as $site) {
			$siteIds[] = $site->getId();
		}
		$variantData = [];
		if (null !== $variants) {
			foreach ($variants->findByAsset($asset) as $variant) {
				$variantData[] = [
					'key' => $variant->getKey(),
					'mimeType' => $variant->getMimeType(),
					'width' => $variant->getWidth(),
					'height' => $variant->getHeight(),
					'size' => $variant->getSize(),
					'checksum' => $variant->getChecksum(),
					'format' => $variant->getFormat(),
					'quality' => $variant->getQuality(),
					'focalCrop' => $variant->isFocalCrop(),
					'url' => '/assets/'.$asset->getPublicToken().'/'.$variant->getKey(),
				];
			}
		}
		$runs = [];
		if (null !== $entityManager) {
			$assetId = $asset->getId();
			if (null !== $assetId) {
				foreach ($entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', (string) $assetId) as $instance) {
					$marking = $instance->getMarking();
					$runs[] = [
						'workflow' => $instance->getDefinitionVersion()->getDefinition()->getKey(),
						'version' => $instance->getDefinitionVersion()->getVersion(),
						'status' => 1 === count($marking) ? array_key_first($marking) : 'parallel',
						'executionState' => $instance->getExecutionState(),
						'attempts' => $instance->getAttempts(),
						'error' => $instance->getLastError(),
					];
				}
			}
		}

		$defaultAlt = $this->serialiseLocalizedProperty($asset, 'default_alt', $localizedProperties, $requestedLocale);
		$defaultCaption = $this->serialiseLocalizedProperty($asset, 'default_caption', $localizedProperties, $requestedLocale);
		$description = $this->serialiseLocalizedProperty($asset, 'description', $localizedProperties, $requestedLocale);

		return [
			'id' => $asset->getId(),
			'type' => $asset instanceof \iikiti\CMS\Entity\Object\UploadedDigitalAsset ? 'upload' : 'external',
			'title' => $asset->getTitle(),
			'mimeType' => $asset->getMimeType(),
			'originalName' => $asset->getOriginalName(),
			'size' => $asset->getSize(),
			'width' => $asset->getWidth(),
			'height' => $asset->getHeight(),
			'focalPoint' => [
				'x' => $asset->getEffectiveFocalX(),
				'y' => $asset->getEffectiveFocalY(),
			],
			'dominantColor' => $asset->getDominantColor(),
			'blurhash' => $asset->getBlurhash(),
			'durationMs' => $asset->getDurationMs(),
			'mediaMetadata' => $asset->getMediaMetadata(),
			'defaultAlt' => $defaultAlt['value'],
			'defaultAltLocale' => $defaultAlt['locale'],
			'defaultAltTranslations' => [] !== $defaultAlt['translations'] ? $defaultAlt['translations'] : new \stdClass(),
			'defaultCaption' => $defaultCaption['value'],
			'defaultCaptionLocale' => $defaultCaption['locale'],
			'defaultCaptionTranslations' => [] !== $defaultCaption['translations'] ? $defaultCaption['translations'] : new \stdClass(),
			'description' => $description['value'],
			'descriptionLocale' => $description['locale'],
			'descriptionTranslations' => [] !== $description['translations'] ? $description['translations'] : new \stdClass(),
			'uri' => $asset->getExternalUri(),
			'visibility' => $asset->getVisibility(),
			'siteIds' => $siteIds,
			'processingStatus' => $asset->getProcessingStatus(),
			'workflowInstances' => $runs,
			'variants' => $variantData,
			'fileUrl' => '/assets/'.$asset->getPublicToken().'/original',
		];
	}

	/**
	 * @return array{value: ?string, locale: ?string, translations: array<string,string>}
	 */
	private function serialiseLocalizedProperty(
		AbstractDigitalAsset $asset,
		string $propertyName,
		?LocalizedPropertyResolver $resolver,
		?LocaleTag $requestedLocale,
	): array {
		$property = $asset->getProperties()->get($propertyName);
		if (null === $property) {
			return ['value' => null, 'locale' => null, 'translations' => []];
		}

		$localized = $property->getLocalizedValue();
		if (null !== $localized) {
			$translations = $localized->getTranslations();
			$resolved = $resolver?->resolve($asset, $property, $requestedLocale);
			if (null !== $resolved) {
				$resolvedValue = null;
				if (is_string($resolved->value)) {
					$resolvedValue = $resolved->value;
				} elseif (is_array($resolved->value)) {
					$resolvedValue = implode('', array_map(static fn (array $segment): string => $segment['text'], $resolved->value));
				}

				return [
					'value' => $resolvedValue,
					'locale' => $resolved->locale?->getTag(),
					'translations' => $translations,
				];
			}

			$firstLocale = array_key_first($translations);

			return [
				'value' => null !== $firstLocale ? $translations[$firstLocale] : null,
				'locale' => is_string($firstLocale) ? $firstLocale : null,
				'translations' => $translations,
			];
		}

		$raw = $property->getValue();

		return [
			'value' => is_string($raw) ? $raw : null,
			'locale' => null,
			'translations' => [],
		];
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
}
