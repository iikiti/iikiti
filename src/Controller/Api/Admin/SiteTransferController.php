<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\CredentialRefreshRequired;
use iikiti\CMS\Batch\BatchAccessPolicy;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Batch\BatchJob;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\AssetStorage;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Transfer\RemoteSiteClient;
use iikiti\CMS\Transfer\SiteTransferCatalog;
use iikiti\CMS\Transfer\SiteTransferExportService;
use iikiti\CMS\Transfer\SiteTransferImportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/site-transfer', name: 'api_admin_site_transfer_')]
final class SiteTransferController extends AppController
{
	#[Route('/sites', name: 'sites', methods: ['GET'])]
	public function sites(DigitalAssetAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$sites = $access->getManageableSites($user);

		return $this->json(['sites' => array_map(static function (Site $site): array {
			$properties = $site->getProperties();
			return ['id' => (string) $site->getId(), 'name' => $properties->get('name')?->getValue() ?? $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site', 'domain' => $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? ''];
		}, $sites), 'canCreateSite' => $this->isGranted('ROLE_SUPER_ADMIN') || $this->isGranted('ROLE_SYSTEM')]);
	}

	#[Route('/catalog', name: 'catalog', methods: ['GET'])]
	public function catalog(Request $request, SiteTransferCatalog $catalog, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		$siteId = $request->query->get('siteId');
		if (null === $user || !is_scalar($siteId) || !$access->canManageSite($user, (string) $siteId)) {
			return $this->forbidden();
		}

		return $this->json(['types' => $catalog->describe((string) $siteId)]);
	}

	#[Route('/exports', name: 'export_create', methods: ['POST'])]
	public function createExport(Request $request, SiteTransferExportService $exports, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		try {
			$data = $request->toArray();
			$siteId = $data['siteId'] ?? null;
			if (!is_scalar($siteId) || !$access->canManageSite($user, (string) $siteId)) {
				return $this->forbidden();
			}
			$types = $data['types'] ?? null;
			$properties = $data['properties'] ?? [];
			if (!is_array($types) || !is_array($properties)) {
				return $this->json(['error' => 'Types and property selection must be arrays.'], Response::HTTP_BAD_REQUEST);
			}
			$job = $exports->create((string) $siteId, $types, $properties, $user->getId() ?? throw new \LogicException('Admin user has no ID.'));

			return $this->json(['jobId' => (string) $job->getId(), 'status' => $job->getStatus(), 'total' => $job->getTotalItems()], Response::HTTP_ACCEPTED);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
	}

	#[Route('/exports/{id}', name: 'export_info', requirements: ['id' => '\\d+'], methods: ['GET'])]
	public function exportInfo(int $id, EntityManagerInterface $entityManager, SiteTransferExportService $exports, BatchAccessPolicy $access): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}
		try {
			$header = $exports->getHeader($job);
		} catch (\InvalidArgumentException) {
			return $this->notFound();
		}

		return $this->json(['id' => (string) $job->getId(), 'status' => $job->getStatus(), 'manifest' => $header, 'manifestUrl' => sprintf('/api/admin/site-transfer/exports/%s/manifest', $job->getId())]);
	}

	#[Route('/exports/{id}/manifest', name: 'export_manifest', requirements: ['id' => '\\d+'], methods: ['GET'])]
	public function manifest(int $id, EntityManagerInterface $entityManager, SiteTransferExportService $exports, BatchAccessPolicy $access): Response
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access) || !in_array($job->getStatus(), [BatchJob::STATUS_COMPLETED, BatchJob::STATUS_COMPLETED_WITH_ERRORS], true)) {
			return $this->notFound();
		}
		$path = $exports->getManifestPath($job);
		if (!is_file($path)) {
			return $this->notFound();
		}
		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', 'application/x-ndjson; charset=utf-8');
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->setContentDisposition('attachment', sprintf('site-export-%s.ndjson', $id));

		return $response;
	}

	#[Route('/exports/{id}/assets/{assetId}', name: 'export_asset', requirements: ['id' => '\\d+', 'assetId' => '\\d+'], methods: ['GET'])]
	public function exportAsset(int $id, int $assetId, EntityManagerInterface $entityManager, DigitalAssetRepository $assets, DigitalAssetAccessPolicy $assetAccess, SiteTransferExportService $exports, BatchAccessPolicy $access, AssetStorage $storage): Response
	{
		$user = $this->adminUser();
		$job = $entityManager->find(BatchJob::class, $id);
		if (null === $user || !$job instanceof BatchJob || !$access->canAccessJob($user, $job) || !in_array($job->getStatus(), [BatchJob::STATUS_COMPLETED, BatchJob::STATUS_COMPLETED_WITH_ERRORS], true)) {
			return $this->notFound();
		}
		try {
			$header = $exports->getHeader($job);
		} catch (\InvalidArgumentException) {
			return $this->notFound();
		}
		$asset = $assets->find($assetId);
		$site = $entityManager->find(Site::class, $header['sourceSiteId'] ?? 0);
		if (!$asset instanceof AbstractDigitalAsset || !$site instanceof Site || !in_array($asset::class, $header['types'] ?? [], true) || !$assetAccess->canManageAsset($user, $asset) || (!$asset->grantsAccessTo($site) && (string) $asset->getSiteId() !== (string) $site->getId())) {
			return $this->json(['error' => 'Asset is not available to this transfer user.'], Response::HTTP_FORBIDDEN);
		}
		if ($asset instanceof ExternalDigitalAsset) {
			$uri = $asset->getExternalUri();
			$parts = is_string($uri) ? parse_url($uri) : false;
			if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
				return $this->json(['error' => 'External asset URL is invalid.'], Response::HTTP_NOT_FOUND);
			}
			$response = $this->redirect($uri, Response::HTTP_FOUND);
			$response->headers->set('Cache-Control', 'private, no-store');

			return $response;
		}
		if (!$asset instanceof \iikiti\CMS\Entity\Object\UploadedDigitalAsset || null === $asset->getStorageKey()) {
			return $this->json(['error' => 'This asset has no locally downloadable binary.'], Response::HTTP_NOT_FOUND);
		}
		$path = $storage->pathFor($asset->getStorageKey());
		if (!is_file($path)) {
			return $this->json(['error' => 'Asset binary is missing.'], Response::HTTP_NOT_FOUND);
		}
		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', $asset->getMimeType());
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->setContentDisposition('attachment', $asset->getOriginalName() ?? $asset->getTitle());

		return $response;
	}

	#[Route('/imports/preview', name: 'import_preview', methods: ['POST'])]
	public function previewImport(Request $request, SiteTransferImportService $imports): JsonResponse
	{
		if (null === $this->adminUser()) {
			return $this->forbidden();
		}
		try {
			$data = $request->toArray();
			return $this->json(['manifest' => $imports->preview((string) ($data['sourceUrl'] ?? ''), (string) ($data['exportId'] ?? ''), (string) ($data['sourceToken'] ?? ''))]);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (CredentialRefreshRequired) {
			return $this->json(['error' => 'Source API token is expired or invalid.'], Response::HTTP_UNAUTHORIZED);
		} catch (\Throwable) {
			return $this->json(['error' => 'Unable to reach or validate the source export.'], Response::HTTP_BAD_GATEWAY);
		}
	}

	#[Route('/imports', name: 'import_create', methods: ['POST'])]
	public function createImport(Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, SiteTransferImportService $imports, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$newSite = false;
		$site = null;
		try {
			$data = $request->toArray();
			$sourceUrl = (string) ($data['sourceUrl'] ?? '');
			$exportId = (string) ($data['exportId'] ?? '');
			$sourceToken = (string) ($data['sourceToken'] ?? '');
			$header = $imports->preview($sourceUrl, $exportId, $sourceToken);
			if (isset($data['targetSiteId'])) {
				$site = $sites->find((string) $data['targetSiteId']);
				if (!$site instanceof Site || !$access->canManageSite($user, $site->getId())) {
					return $this->forbidden();
				}
			} else {
				if (!$access->isGlobalAdmin($user) || !is_array($data['newSite'] ?? null)) {
					return $this->forbidden();
				}
				$site = $this->createSite($data['newSite'], $user, $entityManager);
				$newSite = true;
			}
			$types = is_array($data['types'] ?? null) ? $data['types'] : [];
			$properties = is_array($data['properties'] ?? null) ? $data['properties'] : [];
			$job = $imports->create($sourceUrl, $exportId, $sourceToken, $header, $types, $properties, $site, $user->getId() ?? throw new \LogicException('Admin user has no ID.'), (int) ($data['maxAttempts'] ?? 3));
			return $this->json(['jobId' => (string) $job->getId(), 'status' => $job->getStatus(), 'total' => $job->getTotalItems(), 'destinationSiteId' => (string) $site->getId()], Response::HTTP_ACCEPTED);
		} catch (\InvalidArgumentException $exception) {
			if ($newSite && $site instanceof Site) {
				$entityManager->remove($site);
				$entityManager->flush();
			}
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (CredentialRefreshRequired) {
			if ($newSite && $site instanceof Site) {
				$storedSite = $entityManager->find(Site::class, $site->getId());
				if ($storedSite instanceof Site) {
					$entityManager->remove($storedSite);
					$entityManager->flush();
				}
			}
			return $this->json(['error' => 'Source API token is expired or invalid.'], Response::HTTP_UNAUTHORIZED);
		} catch (\Throwable) {
			if ($newSite && $site instanceof Site) {
				$storedSite = $entityManager->find(Site::class, $site->getId());
				if ($storedSite instanceof Site) {
					$entityManager->remove($storedSite);
					$entityManager->flush();
				}
			}
			return $this->json(['error' => 'Unable to create the site import job.'], Response::HTTP_BAD_GATEWAY);
		}
	}

	#[Route('/imports/{id}/credentials', name: 'import_credentials', requirements: ['id' => '\\d+'], methods: ['POST'])]
	public function refreshImportCredentials(int $id, Request $request, EntityManagerInterface $entityManager, BatchAccessPolicy $access, SiteTransferImportService $imports): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access) || BatchJob::STATUS_PAUSED !== $job->getStatus()) {
			return $this->notFound();
		}
		try {
			$token = (string) ($request->toArray()['sourceToken'] ?? '');
			if (!$imports->refreshCredentials($id, $token)) {
				return $this->json(['error' => 'Import job cannot be resumed.'], Response::HTTP_CONFLICT);
			}
			return $this->json(['resumed' => true]);
		} catch (\Throwable) {
			return $this->json(['error' => 'Source credentials were rejected.'], Response::HTTP_UNAUTHORIZED);
		}
	}

	/** @param array<string,mixed> $data */
	private function createSite(array $data, User $user, EntityManagerInterface $entityManager): Site
	{
		$name = trim((string) ($data['name'] ?? ''));
		$domain = strtolower(trim((string) ($data['domain'] ?? '')));
		if ('' === $name || mb_strlen($name) > 255 || '' === $domain || str_contains($domain, '/') || str_contains($domain, '@') || false === filter_var('https://'.$domain, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('New destination site requires a valid name and domain.');
		}
		$site = new Site();
		$site->setProperties(new ArrayCollection());
		$site->setSiteId(0);
		$site->setCreatorId($user->getId() ?? throw new \LogicException('Admin user has no ID.'));
		$site->setCreatedDate(new \DateTimeImmutable());
		$site->setProperty('name', $name);
		$site->setProperty(Site::DOMAIN_PROPERTY_KEY, $domain);
		$entityManager->persist($site);
		$entityManager->flush();

		return $site;
	}

	private function canAccess(BatchJob $job, BatchAccessPolicy $access): bool
	{
		$user = $this->adminUser();

		return null !== $user && $access->canAccessJob($user, $job);
	}

	private function adminUser(): ?User
	{
		$user = $this->getUser();

		return $user instanceof User && $this->isGranted('ROLE_ADMIN') ? $user : null;
	}

	private function forbidden(): JsonResponse { return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN); }
	private function notFound(): JsonResponse { return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND); }
}
