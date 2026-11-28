<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Controller;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Service\AssetStreamResponseFactory;
use iikiti\Extension\MediaAv\Service\HlsTranscoder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Delivers HLS playlists (.m3u8) and MPEG-TS segments (.ts) while enforcing
 * plugin activation, MFA completion, site access grants, and asset visibility.
 */
#[RequiresPlugin('iikiti-media-av')]
final class HlsDeliveryController extends AbstractController
{
	public function __construct(
		private readonly Security $security,
		private readonly MfaAuthenticationGate $mfaGate,
		private readonly AssetStreamResponseFactory $responseFactory,
	) {
	}

	#[Route(
		'/assets/{publicToken}/hls/{segment}',
		name: 'media_av_hls_delivery',
		requirements: [
			'publicToken' => '[a-f0-9]{64}',
			'segment' => '[a-z0-9][a-z0-9_-]{0,55}\.(?:m3u8|ts)',
		],
		methods: ['GET', 'HEAD'],
	)]
	public function __invoke(
		Request $request,
		string $publicToken,
		string $segment,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		SiteRegistry $siteRegistry,
	): Response {
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

		$variantKey = HlsTranscoder::variantKeyForSegment($segment);
		if (null === $variantKey) {
			throw $this->createNotFoundException();
		}

		$record = $variants->findByAssetAndKey($asset, $variantKey);
		if (!$record instanceof DigitalAssetVariant) {
			throw $this->createNotFoundException();
		}

		return $this->responseFactory->createResponse(
			$request,
			$record->getStorageKey(),
			$record->getMimeType(),
			$segment,
			$asset->getVisibility(),
			$record->getChecksum(),
			(int) $record->getSize(),
			null,
			false,
		);
	}
}
