<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\ExternalDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Service\AssetStorage;
use iikiti\CMS\Web\BlockEditor\PublicAssetApiProxy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DigitalAssetFileController extends AbstractController
{
	public function __construct(
		private readonly PublicAssetApiProxy $apiProxy,
		private readonly Security $security,
		private readonly MfaAuthenticationGate $mfaGate,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole,
	) {
	}

	#[Route('/assets/{publicToken}/{variant}', name: 'digital_asset_file', defaults: ['variant' => 'original'], requirements: ['publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET'])]
	public function __invoke(
		Request $request,
		string $publicToken,
		string $variant,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		SiteRegistry $siteRegistry,
		AssetStorage $storage,
	): Response {
		if ('consumer' === $this->applicationRole) {
			return $this->apiProxy->file($request, $publicToken, $variant);
		}
		$user = $this->security->getUser();
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			throw new AccessDeniedHttpException('Complete the MFA challenge before accessing private assets.');
		}

		$site = $siteRegistry::getCurrent();
		$asset = $assets->findPublicTokenForSite($publicToken, $site);
		if (!$asset instanceof AbstractDigitalAsset) {
			throw $this->createNotFoundException();
		}
		if ('private' === $asset->getVisibility() && !($this->getUser() instanceof User)) {
			throw $this->createAccessDeniedException();
		}
		if ($asset instanceof ExternalDigitalAsset) {
			if (null === $asset->getExternalUri()) {
				throw $this->createNotFoundException();
			}

			return $this->redirect($asset->getExternalUri(), Response::HTTP_FOUND);
		}

		if ('original' === $variant) {
			$key = $asset->getStorageKey();
			$mimeType = $asset->getMimeType();
			$filename = $asset->getOriginalName() ?? $asset->getTitle();
		} else {
			$record = $variants->findByAssetAndKey($asset, $variant);
			if (!$record instanceof DigitalAssetVariant) {
				throw $this->createNotFoundException();
			}
			$key = $record->getStorageKey();
			$mimeType = $record->getMimeType();
			$filename = $asset->getTitle().'-'.$record->getKey();
		}
		if (null === $key) {
			throw $this->createNotFoundException();
		}
		$path = $storage->pathFor($key);
		if (!is_file($path)) {
			throw $this->createNotFoundException();
		}

		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', $mimeType);
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('Cache-Control', 'private' === $asset->getVisibility() ? 'private, no-store' : 'public, max-age=3600');
		$disposition = str_starts_with($mimeType, 'image/') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
		$response->setContentDisposition($disposition, $filename);

		return $response;
	}
}
