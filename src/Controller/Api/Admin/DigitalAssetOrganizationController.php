<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\DigitalAssetCollectionRepository;
use iikiti\CMS\Repository\DigitalAssetFolderRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetTagRepository;
use iikiti\CMS\Service\DigitalAssetOrganizationManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/assets', name: 'api_admin_digital_asset_org_')]
final class DigitalAssetOrganizationController extends AppController
{
	// ------------------------------------------------------------------
	// Folders
	// ------------------------------------------------------------------

	#[Route('/folders', name: 'folders_index', methods: ['GET'])]
	public function listFolders(DigitalAssetOrganizationManager $manager): JsonResponse
	{
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$folders = $manager->listManageableFolders($user);

		return $this->json([
			'folders' => array_map(
				static fn (DigitalAssetFolder $folder): array => $manager->serializeFolder($folder),
				$folders,
			),
		]);
	}

	#[Route('/folders', name: 'folders_create', methods: ['POST'])]
	public function createFolder(
		Request $request,
		DigitalAssetFolderRepository $folders,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		$parent = null;
		if (array_key_exists('parentId', $payload) && null !== $payload['parentId'] && '' !== $payload['parentId']) {
			if (!is_int($payload['parentId']) && !(is_string($payload['parentId']) && ctype_digit(trim($payload['parentId'])))) {
				return $this->json(['error' => 'parentId must be a positive integer or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
			}
			$parent = $folders->find(trim((string) $payload['parentId']));
			if (!$parent instanceof DigitalAssetFolder) {
				return $this->json(['error' => 'Parent folder not found.'], Response::HTTP_UNPROCESSABLE_ENTITY);
			}
		}

		$siteIds = $payload['siteIds'] ?? [];
		if (!is_array($siteIds)) {
			return $this->json(['error' => 'siteIds must be a list of site IDs.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		try {
			$folder = $manager->createFolder(
				(string) ($payload['name'] ?? ''),
				$parent,
				array_values($siteIds),
				$user,
				isset($payload['slug']) && is_string($payload['slug']) ? $payload['slug'] : null,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		return $this->json($manager->serializeFolder($folder), Response::HTTP_CREATED);
	}

	#[Route('/folders/{id}', name: 'folders_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function updateFolder(
		int $id,
		Request $request,
		DigitalAssetFolderRepository $folders,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$folder = $folders->find($id);
		if (!$folder instanceof DigitalAssetFolder || !$manager->canManageFolder($user, $folder)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		try {
			$updated = $manager->updateFolder($folder, $this->payload($request), $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		return $this->json($manager->serializeFolder($updated));
	}

	#[Route('/folders/{id}', name: 'folders_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function deleteFolder(
		int $id,
		DigitalAssetFolderRepository $folders,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$folder = $folders->find($id);
		if (!$folder instanceof DigitalAssetFolder || !$manager->canManageFolder($user, $folder)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		try {
			$manager->deleteFolder($folder, $user);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		return $this->json(['deleted' => true]);
	}

	// ------------------------------------------------------------------
	// Collections
	// ------------------------------------------------------------------

	#[Route('/collections', name: 'collections_index', methods: ['GET'])]
	public function listCollections(DigitalAssetOrganizationManager $manager): JsonResponse
	{
		if (null === $this->authenticatedAdmin()) {
			return $this->forbidden();
		}

		$collections = $manager->listCollections();

		return $this->json([
			'collections' => array_map(
				static fn (DigitalAssetCollection $collection): array => $manager->serializeCollection($collection),
				$collections,
			),
		]);
	}

	#[Route('/collections', name: 'collections_create', methods: ['POST'])]
	public function createCollection(
		Request $request,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		try {
			$collection = $manager->createCollection(
				(string) ($payload['name'] ?? ''),
				isset($payload['description']) && is_string($payload['description']) ? $payload['description'] : null,
				$user,
				isset($payload['slug']) && is_string($payload['slug']) ? $payload['slug'] : null,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($manager->serializeCollection($collection), Response::HTTP_CREATED);
	}

	#[Route('/collections/{id}', name: 'collections_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function updateCollection(
		int $id,
		Request $request,
		DigitalAssetCollectionRepository $collections,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$collection = $collections->find($id);
		if (!$collection instanceof DigitalAssetCollection) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		try {
			$updated = $manager->updateCollection($collection, $this->payload($request), $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($manager->serializeCollection($updated));
	}

	#[Route('/collections/{id}', name: 'collections_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function deleteCollection(
		int $id,
		DigitalAssetCollectionRepository $collections,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$collection = $collections->find($id);
		if (!$collection instanceof DigitalAssetCollection) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$manager->deleteCollection($collection, $user);

		return $this->json(['deleted' => true]);
	}

	#[Route('/collections/{id}/items', name: 'collections_add_items', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function addCollectionItems(
		int $id,
		Request $request,
		DigitalAssetCollectionRepository $collections,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$collection = $collections->find($id);
		if (!$collection instanceof DigitalAssetCollection) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$payload = $this->payload($request);
		$assetIds = $payload['assetIds'] ?? (array_key_exists('assetId', $payload) ? [$payload['assetId']] : null);
		if (!is_array($assetIds)) {
			return $this->json(['error' => 'assetIds must be a list of asset IDs.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		try {
			$added = $manager->addAssetsToCollection($collection, array_values($assetIds), $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		$response = $manager->serializeCollection($collection);
		$response['addedAssetIds'] = $added;

		return $this->json($response);
	}

	#[Route('/collections/{id}/items/{assetId}', name: 'collections_remove_item', methods: ['DELETE'], requirements: ['id' => '\\d+', 'assetId' => '\\d+'])]
	public function removeCollectionItem(
		int $id,
		int $assetId,
		DigitalAssetCollectionRepository $collections,
		DigitalAssetRepository $assets,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$collection = $collections->find($id);
		if (!$collection instanceof DigitalAssetCollection) {
			return $this->json(['error' => 'Collection not found'], Response::HTTP_NOT_FOUND);
		}

		$asset = $assets->find($assetId);
		if (!$asset instanceof AbstractDigitalAsset) {
			return $this->json(['error' => 'Asset not found'], Response::HTTP_NOT_FOUND);
		}

		try {
			$manager->removeAssetFromCollection($collection, $asset, $user);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		}

		$response = $manager->serializeCollection($collection);
		$response['removed'] = true;

		return $this->json($response);
	}

	// ------------------------------------------------------------------
	// Tags
	// ------------------------------------------------------------------

	#[Route('/tags', name: 'tags_index', methods: ['GET'])]
	public function listTags(Request $request, DigitalAssetOrganizationManager $manager): JsonResponse
	{
		if (null === $this->authenticatedAdmin()) {
			return $this->forbidden();
		}

		$namespace = $request->query->get('namespace');
		$query = $request->query->get('q');

		try {
			$tags = $manager->listTags(
				is_string($namespace) && '' !== trim($namespace) ? $namespace : null,
				is_string($query) && '' !== trim($query) ? $query : null,
			);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json([
			'tags' => array_map(
				static fn (DigitalAssetTag $tag): array => $manager->serializeTag($tag),
				$tags,
			),
		]);
	}

	#[Route('/tags', name: 'tags_create', methods: ['POST'])]
	public function createTag(
		Request $request,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		$name = (string) ($payload['name'] ?? '');
		$namespace = isset($payload['namespace']) && is_string($payload['namespace'])
			? $payload['namespace']
			: DigitalAssetTag::DEFAULT_NAMESPACE;
		$slug = isset($payload['slug']) && is_string($payload['slug']) ? $payload['slug'] : null;
		$findOrCreate = (bool) ($payload['findOrCreate'] ?? false);

		try {
			$tag = $findOrCreate
				? $manager->findOrCreateTag($name, $user, $namespace, $slug)
				: $manager->createTag($name, $user, $namespace, $slug);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($manager->serializeTag($tag), Response::HTTP_CREATED);
	}

	#[Route('/tags/{id}', name: 'tags_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function updateTag(
		int $id,
		Request $request,
		DigitalAssetTagRepository $tags,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$tag = $tags->find($id);
		if (!$tag instanceof DigitalAssetTag) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		try {
			$updated = $manager->updateTag($tag, $this->payload($request), $user);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($manager->serializeTag($updated));
	}

	#[Route('/tags/{id}', name: 'tags_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function deleteTag(
		int $id,
		DigitalAssetTagRepository $tags,
		DigitalAssetOrganizationManager $manager,
	): JsonResponse {
		$user = $this->authenticatedAdmin();
		if (null === $user) {
			return $this->forbidden();
		}

		$tag = $tags->find($id);
		if (!$tag instanceof DigitalAssetTag) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$manager->deleteTag($tag, $user);

		return $this->json(['deleted' => true]);
	}

	private function authenticatedAdmin(): ?User
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return null;
		}

		return $user;
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
