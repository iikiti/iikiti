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
use iikiti\CMS\Service\AssetSignedUrlService;
use iikiti\CMS\Service\AssetStreamResponseFactory;
use iikiti\CMS\Web\BlockEditor\PublicAssetApiProxy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
		private readonly AssetStreamResponseFactory $responseFactory,
		private readonly AssetSignedUrlService $signedUrls,
	) {
	}

	#[Route('/assets/{publicToken}/{variant}', name: 'digital_asset_file', defaults: ['variant' => 'original'], requirements: ['publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET', 'HEAD'])]
	public function __invoke(
		Request $request,
		string $publicToken,
		string $variant,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		SiteRegistry $siteRegistry,
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
		if ($asset->isEffectivelyPrivate() && !($this->getUser() instanceof User)) {
			throw $this->createAccessDeniedException();
		}

		return $this->deliverAsset($request, $asset, $variant, $variants, null, false);
	}

	#[Route('/assets/signed/{publicToken}/{variant}', name: 'digital_asset_signed_file', defaults: ['variant' => 'original'], requirements: ['publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET', 'HEAD'])]
	public function signed(
		Request $request,
		string $publicToken,
		string $variant,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		SiteRegistry $siteRegistry,
	): Response {
		if ('consumer' === $this->applicationRole) {
			return $this->apiProxy->signedFile($request, $publicToken, $variant);
		}

		$site = $siteRegistry::getCurrent();
		$currentSiteId = $site->getId();
		$siteParam = $request->query->get('site');
		$expParam = $request->query->get('exp');
		$sigParam = $request->query->get('sig');
		$disposition = $request->query->get('d', 'inline');
		$versionParam = $request->query->get('v');

		if (
			null === $currentSiteId ||
			!is_string($siteParam) ||
			$siteParam !== (string) $currentSiteId ||
			!is_string($expParam) ||
			!ctype_digit($expParam) ||
			!is_string($sigParam) ||
			!is_string($disposition)
		) {
			throw $this->createAccessDeniedException('Invalid or expired signed asset URL.');
		}

		$version = null;
		if (null !== $versionParam && '' !== $versionParam) {
			if (!is_string($versionParam) || !ctype_digit($versionParam)) {
				throw $this->createAccessDeniedException('Invalid or expired signed asset URL.');
			}
			$version = (int) $versionParam;
		}

		if (!$this->signedUrls->verify($publicToken, $variant, $currentSiteId, (int) $expParam, $disposition, $sigParam, $version)) {
			throw $this->createAccessDeniedException('Invalid or expired signed asset URL.');
		}

		$asset = $assets->findPublicTokenForSite($publicToken, $site);
		if (!$asset instanceof AbstractDigitalAsset) {
			throw $this->createNotFoundException();
		}

		return $this->deliverAsset($request, $asset, $variant, $variants, $disposition, true);
	}

	private function deliverAsset(
		Request $request,
		AbstractDigitalAsset $asset,
		string $variant,
		DigitalAssetVariantRepository $variants,
		?string $forcedDisposition,
		bool $isSigned,
	): Response {
		$effectiveVisibility = $asset->isEffectivelyPrivate() ? 'private' : $asset->getVisibility();

		if ($asset instanceof ExternalDigitalAsset) {
			if (null === $asset->getExternalUri()) {
				throw $this->createNotFoundException();
			}
			$redirect = $this->redirect($asset->getExternalUri(), Response::HTTP_FOUND);
			if ($isSigned || 'private' === $effectiveVisibility) {
				$redirect->headers->set('Cache-Control', 'private, no-store');
			}

			return $redirect;
		}

		if ('original' === $variant) {
			$key = $asset->getStorageKey();
			$mimeType = $asset->getMimeType();
			$filename = $asset->getOriginalName() ?? $asset->getTitle();
			$checksum = $asset->getChecksum();
			$rawSize = $asset->getSize();
			$size = null !== $rawSize ? (int) $rawSize : null;
		} else {
			$record = $variants->findByAssetAndKey($asset, $variant);
			if (!$record instanceof DigitalAssetVariant) {
				throw $this->createNotFoundException();
			}
			$key = $record->getStorageKey();
			$mimeType = $record->getMimeType();
			$filename = $asset->getTitle().'-'.$record->getKey();
			$checksum = $record->getChecksum();
			$size = (int) $record->getSize();
		}

		if (null === $key) {
			throw $this->createNotFoundException();
		}

		return $this->responseFactory->createResponse(
			$request,
			$key,
			$mimeType,
			$filename,
			$effectiveVisibility,
			$checksum,
			$size,
			$forcedDisposition,
			$isSigned,
		);
	}
}
