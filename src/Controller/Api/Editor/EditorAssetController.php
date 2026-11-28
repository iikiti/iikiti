<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Editor;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Exception\DuplicateAssetException;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFilterCriteria;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\DigitalAssetOrganizationManager;
use iikiti\CMS\Service\DigitalAssetSerializer;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site-scoped DAM API for the block editor media picker and inline uploader.
 *
 * Accessible to any authenticated editor with `Page:write` or `Template:write`
 * permission without requiring `ROLE_ADMIN`. Every read and upload is strictly
 * scoped to the active site from {@see SiteRegistry}.
 */
#[AsController]
#[Route('/api/editor/assets', name: 'api_editor_assets_')]
final class EditorAssetController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(
		Request $request,
		PermissionChecker $permissionChecker,
		DigitalAssetRepository $assets,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		if (null === $this->authorizedEditor($permissionChecker)) {
			return $this->forbidden();
		}

		$site = $this->currentSite();
		if (null === $site) {
			return $this->json(['error' => 'Site context is not available.'], Response::HTTP_NOT_FOUND);
		}

		try {
			$requestedLocale = $this->requestedLocale($request);
			$criteria = DigitalAssetFilterCriteria::fromRequest($request, 24, 100)->withoutFacet('siteId');
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$records = $assets->findAccessibleToSiteByCriteria($site, $criteria);
		$totalItems = $assets->countAccessibleToSiteByCriteria($site, $criteria);
		$page = (int) floor($criteria->offset / $criteria->limit) + 1;

		return $this->json([
			'assets' => $serializer->serializeMany($records, $requestedLocale, false),
			'totalItems' => $totalItems,
			'page' => $page,
			'itemsPerPage' => $criteria->limit,
		]);
	}

	#[Route('/organization', name: 'organization', methods: ['GET'])]
	public function organization(
		PermissionChecker $permissionChecker,
		DigitalAssetFolderRepository $folders,
		DigitalAssetTagRepository $tags,
		DigitalAssetCollectionRepository $collections,
		DigitalAssetOrganizationManager $organizationManager,
	): JsonResponse {
		if (null === $this->authorizedEditor($permissionChecker)) {
			return $this->forbidden();
		}

		$site = $this->currentSite();
		if (null === $site) {
			return $this->json(['error' => 'Site context is not available.'], Response::HTTP_NOT_FOUND);
		}

		$accessibleFolders = $folders->findAccessibleToSite($site);
		$allTags = $tags->findAllOrdered();
		$accessibleCollections = $collections->findAccessibleToSite($site);

		return $this->json([
			'folders' => array_map(
				static fn (DigitalAssetFolder $folder): array => $organizationManager->serializeFolder($folder),
				$accessibleFolders,
			),
			'tags' => array_map(
				static fn (DigitalAssetTag $tag): array => $organizationManager->serializeTag($tag),
				$allTags,
			),
			'collections' => array_map(
				static fn (DigitalAssetCollection $collection): array => [
					'id' => $collection->getId(),
					'name' => $collection->getName(),
					'slug' => $collection->getSlug(),
					'description' => $collection->getDescription(),
				],
				$accessibleCollections,
			),
		]);
	}

	#[Route('/check-duplicate', name: 'check_duplicate', methods: ['POST'])]
	public function checkDuplicate(
		Request $request,
		PermissionChecker $permissionChecker,
		DigitalAssetRepository $assets,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		if (null === $this->authorizedEditor($permissionChecker)) {
			return $this->forbidden();
		}

		$site = $this->currentSite();
		$siteId = $site?->getId();
		if (null === $site || null === $siteId) {
			return $this->json(['error' => 'Site context is not available.'], Response::HTTP_NOT_FOUND);
		}

		$payload = json_decode((string) $request->getContent(), true);
		$checksum = is_array($payload) && isset($payload['checksum']) && is_string($payload['checksum'])
			? strtolower(trim($payload['checksum']))
			: '';
		if (1 !== preg_match('/^[a-f0-9]{64}$/', $checksum)) {
			return $this->json(['error' => 'checksum must be a valid 64-character SHA-256 hex digest.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$matches = $assets->findByChecksumForSites($checksum, [$siteId], false);

		return $this->json([
			'duplicate' => [] !== $matches,
			'matches' => $serializer->serializeMany($matches, null, false),
		]);
	}

	#[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function show(
		int $id,
		Request $request,
		PermissionChecker $permissionChecker,
		DigitalAssetRepository $assets,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		if (null === $this->authorizedEditor($permissionChecker)) {
			return $this->forbidden();
		}

		$site = $this->currentSite();
		if (null === $site) {
			return $this->json(['error' => 'Site context is not available.'], Response::HTTP_NOT_FOUND);
		}

		try {
			$requestedLocale = $this->requestedLocale($request);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$asset = $assets->findAccessibleToSite($id, $site);
		if (!$asset instanceof AbstractDigitalAsset) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json($serializer->serialize($asset, $requestedLocale, false));
	}

	#[Route('/upload', name: 'upload', methods: ['POST'])]
	public function upload(
		Request $request,
		PermissionChecker $permissionChecker,
		DigitalAssetManager $manager,
		DigitalAssetSerializer $serializer,
	): JsonResponse {
		$user = $this->authorizedEditor($permissionChecker);
		if (null === $user) {
			return $this->forbidden();
		}

		$site = $this->currentSite();
		if (null === $site) {
			return $this->json(['error' => 'Site context is not available.'], Response::HTTP_NOT_FOUND);
		}

		$file = $request->files->get('file');
		if (!$file instanceof UploadedFile) {
			return $this->json(['error' => 'An uploaded file is required.'], Response::HTTP_BAD_REQUEST);
		}

		$visibilityRaw = strtolower(trim((string) $request->request->get('visibility', 'public')));
		$visibility = '' !== $visibilityRaw ? $visibilityRaw : 'public';

		$rawFolderId = $request->request->get('folderId');
		$folderId = is_string($rawFolderId) && '' !== trim($rawFolderId) ? trim($rawFolderId) : null;
		$duplicatePolicy = strtolower(trim((string) $request->request->get('duplicatePolicy', DigitalAssetManager::DUPLICATE_POLICY_ALLOW)));
		if ('' === $duplicatePolicy) {
			$duplicatePolicy = DigitalAssetManager::DUPLICATE_POLICY_ALLOW;
		}
		$requestParams = $request->request->all();

		try {
			$asset = $manager->createUploadForSite(
				$file,
				(string) $request->request->get('title', ''),
				$visibility,
				$site,
				$user,
				$folderId,
				$this->idList($requestParams['tagIds'] ?? ''),
				$duplicatePolicy,
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

		return $this->json($serializer->serialize($asset, null, false, []), Response::HTTP_CREATED);
	}

	private function authorizedEditor(PermissionChecker $permissionChecker): ?User
	{
		$user = $this->getUser();
		if (!$user instanceof User) {
			return null;
		}

		if (
			!$permissionChecker->canAccess($user, 'Page', 'write')
			&& !$permissionChecker->canAccess($user, 'Template', 'write')
		) {
			return null;
		}

		return $user;
	}

	private function currentSite(): ?Site
	{
		if (!SiteRegistry::hasCurrent()) {
			return null;
		}

		return SiteRegistry::getCurrent();
	}

	private function requestedLocale(Request $request): ?LocaleTag
	{
		$locale = $request->query->get('locale');

		return is_string($locale) ? LocaleTag::tryFromString($locale) : null;
	}

	/** @return list<mixed> */
	private function idList(mixed $value): array
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
					throw new \InvalidArgumentException('tagIds must be a list of tag IDs.');
				}

				return array_values($decoded);
			}

			return array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn (string $v): bool => '' !== $v));
		}
		if (!is_array($value)) {
			throw new \InvalidArgumentException('tagIds must be a list of tag IDs.');
		}

		return array_values($value);
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}
}
