<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Controller;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Security\GuestApiUser;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Service\AssetStreamResponseFactory;
use iikiti\CMS\Web\BlockEditor\PublicAssetApiProxy;
use iikiti\Extension\MediaAv\Service\HlsTranscoder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Delivers HLS playlists (.m3u8) and MPEG-TS segments (.ts) while enforcing
 * plugin activation, MFA completion, site access grants, consumer proxying,
 * effective license privacy, and extension/MIME consistency.
 */
#[RequiresPlugin('iikiti-media-av')]
final class HlsDeliveryController extends AbstractController
{
	public function __construct(
		private readonly Security $security,
		private readonly MfaAuthenticationGate $mfaGate,
		private readonly AssetStreamResponseFactory $responseFactory,
		private readonly ?PublicAssetApiProxy $apiProxy = null,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole = 'standalone',
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
		if ('consumer' === $this->applicationRole && null !== $this->apiProxy) {
			return $this->apiProxy->hls($request, $publicToken, $segment);
		}

		return $this->deliverHls($request, $publicToken, $segment, $assets, $variants, $siteRegistry);
	}

	#[Route(
		'/api/public/assets/{publicToken}/hls/{segment}',
		name: 'api_public_media_av_hls_delivery',
		requirements: [
			'publicToken' => '[a-f0-9]{64}',
			'segment' => '[a-z0-9][a-z0-9_-]{0,55}\.(?:m3u8|ts)',
		],
		methods: ['GET', 'HEAD'],
	)]
	public function publicApi(
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
		if (!$user instanceof User && !$user instanceof GuestApiUser) {
			throw new AccessDeniedHttpException('A site-scoped API credential is required.');
		}
		if ($user instanceof GuestApiUser) {
			$domain = $siteRegistry::getCurrent()->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
			if (!is_string($domain) || 0 !== strcasecmp($user->getSiteHost(), $domain)) {
				throw new AccessDeniedHttpException('Guest API credential is scoped to another site.');
			}
		}
		if ($user instanceof User) {
			$user->setCurrentSite($siteRegistry::getCurrent());
		}

		return $this->deliverHls($request, $publicToken, $segment, $assets, $variants, $siteRegistry);
	}

	private function deliverHls(
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

		$isEffectivelyPrivate = $asset->isEffectivelyPrivate();
		if ($isEffectivelyPrivate && !($user instanceof User)) {
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

		$expectedMime = str_ends_with($segment, '.m3u8')
			? HlsTranscoder::PLAYLIST_MIME_TYPE
			: HlsTranscoder::SEGMENT_MIME_TYPE;
		if (strtolower(trim($record->getMimeType())) !== $expectedMime) {
			throw $this->createNotFoundException();
		}

		$effectiveVisibility = $isEffectivelyPrivate
			? AbstractDigitalAsset::VISIBILITY_PRIVATE
			: AbstractDigitalAsset::VISIBILITY_PUBLIC;

		return $this->responseFactory->createResponse(
			$request,
			$record->getStorageKey(),
			$record->getMimeType(),
			$segment,
			$effectiveVisibility,
			$record->getChecksum(),
			(int) $record->getSize(),
			null,
			false,
			true,
		);
	}
}
