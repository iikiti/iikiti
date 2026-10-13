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
use iikiti\CMS\Service\AssetStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class DigitalAssetFileController extends AbstractController
{
	#[Route('/assets/{publicToken}/{variant}', name: 'digital_asset_file', defaults: ['variant' => 'original'], requirements: ['publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET'])]
	public function __invoke(
		string $publicToken,
		string $variant,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		SiteRegistry $siteRegistry,
		AssetStorage $storage,
	): Response {
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
