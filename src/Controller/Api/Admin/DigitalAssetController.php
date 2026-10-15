<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/assets', name: 'api_admin_digital_assets_')]
final class DigitalAssetController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(Request $request, DigitalAssetRepository $assets, EntityManagerInterface $entityManager, DigitalAssetVariantRepository $variants, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
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
			'assets' => array_map(fn (AbstractDigitalAsset $asset): array => $this->serialise($asset, $entityManager, $variants), $records),
			'totalItems' => $totalItems,
			'page' => $page,
			'itemsPerPage' => $perPage,
		]);
	}

	#[Route('/sites', name: 'sites', methods: ['GET'])]
	public function sites(DigitalAssetAccessPolicy $accessPolicy, SiteRegistry $siteRegistry): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$records = $accessPolicy->getManageableSites($user);

		return $this->json(['sites' => array_map(static function (Site $site): array {
			$properties = $site->getProperties();

			return [
				'id' => $site->getId(),
				'domain' => $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? '',
				'name' => $properties->get('name')?->getValue() ?? $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site',
			];
		}, $records), 'defaultSiteId' => $siteRegistry::getCurrent()->getId()]);
	}

	#[Route('/upload', name: 'upload', methods: ['POST'])]
	public function upload(Request $request, DigitalAssetManager $manager): JsonResponse
	{
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

		return $this->json($this->serialise($asset, null, null), Response::HTTP_CREATED);
	}

	#[Route('/uri', name: 'create_uri', methods: ['POST'])]
	public function createUri(Request $request, DigitalAssetManager $manager): JsonResponse
	{
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

		return $this->json($this->serialise($asset, null, null), Response::HTTP_CREATED);
	}

	#[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function show(int $id, DigitalAssetRepository $assets, EntityManagerInterface $entityManager, DigitalAssetVariantRepository $variants, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
		}
		$asset = $assets->find($id);
		if (!$asset instanceof AbstractDigitalAsset || !$accessPolicy->canManageAsset($user, $asset)) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json($this->serialise($asset, $entityManager, $variants));
	}

	#[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function update(int $id, Request $request, DigitalAssetRepository $assets, DigitalAssetManager $manager, EntityManagerInterface $entityManager, DigitalAssetVariantRepository $variants, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
			return $this->forbidden();
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

		return $this->json($this->serialise($asset, $entityManager, $variants));
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

	/** @return array<string,mixed> */
	private function serialise(AbstractDigitalAsset $asset, ?EntityManagerInterface $entityManager, ?DigitalAssetVariantRepository $variants): array
	{
		$siteIds = [];
		foreach ($asset->getAccessSites() as $site) {
			$siteIds[] = $site->getId();
		}
		$variantData = [];
		if (null !== $variants) {
			foreach ($variants->findByAsset($asset) as $variant) {
				$variantData[] = ['key' => $variant->getKey(), 'mimeType' => $variant->getMimeType(), 'width' => $variant->getWidth(), 'height' => $variant->getHeight(), 'url' => '/assets/'.$asset->getPublicToken().'/'.$variant->getKey()];
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

		return [
			'id' => $asset->getId(),
			'type' => $asset instanceof \iikiti\CMS\Entity\Object\UploadedDigitalAsset ? 'upload' : 'external',
			'title' => $asset->getTitle(),
			'mimeType' => $asset->getMimeType(),
			'originalName' => $asset->getOriginalName(),
			'size' => $asset->getSize(),
			'uri' => $asset->getExternalUri(),
			'visibility' => $asset->getVisibility(),
			'siteIds' => $siteIds,
			'processingStatus' => $asset->getProcessingStatus(),
			'workflowInstances' => $runs,
			'variants' => $variantData,
			'fileUrl' => '/assets/'.$asset->getPublicToken().'/original',
		];
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
