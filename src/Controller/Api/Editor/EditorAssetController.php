<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Editor;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Service\DigitalAssetManager;
use iikiti\CMS\Service\DigitalAssetSerializer;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
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
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$query = $this->queryParam($request, 'q');
		$mimeCategory = $this->queryParam($request, 'mimeCategory');
		$mimeType = $this->queryParam($request, 'mimeType');
		$page = max(1, $request->query->getInt('page', 1));
		$perPage = min(100, max(1, $request->query->getInt('itemsPerPage', 24)));

		$records = $assets->findAccessibleToSiteFiltered($site, $query, $mimeCategory, $mimeType, $perPage, ($page - 1) * $perPage);
		$totalItems = $assets->countAccessibleToSiteFiltered($site, $query, $mimeCategory, $mimeType);

		return $this->json([
			'assets' => $serializer->serializeMany($records, $requestedLocale, false),
			'totalItems' => $totalItems,
			'page' => $page,
			'itemsPerPage' => $perPage,
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

		try {
			$asset = $manager->createUploadForSite(
				$file,
				(string) $request->request->get('title', ''),
				$visibility,
				$site,
				$user,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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

	private function queryParam(Request $request, string $name): ?string
	{
		$value = $request->query->get($name);
		if (!is_string($value)) {
			return null;
		}
		$trimmed = trim($value);

		return '' !== $trimmed ? $trimmed : null;
	}

	private function requestedLocale(Request $request): ?LocaleTag
	{
		$locale = $request->query->get('locale');

		return is_string($locale) ? LocaleTag::tryFromString($locale) : null;
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}
}
