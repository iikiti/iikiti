<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api;

use iikiti\CMS\Controller\DigitalAssetFileController;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiUser;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Service\AssetStorage;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PublicAssetController
{
	public function __construct(
		private Security $security,
		private SiteRegistry $sites,
		private DigitalAssetFileController $files,
		private MfaAuthenticationGate $mfaGate,
	) {
	}

	#[Route('/api/public/assets/{publicToken}/{variant}', name: 'api_public_asset_file', requirements: ['publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET'])]
	#[Route('/api/public/sites/{siteHost}/assets/{publicToken}/{variant}', name: 'api_public_site_asset_file', requirements: ['siteHost' => '[a-zA-Z0-9.-]+', 'publicToken' => '[a-f0-9]{64}', 'variant' => '[a-z][a-z0-9_-]{0,63}'], methods: ['GET'])]
	public function file(
		Request $request,
		string $publicToken,
		string $variant,
		DigitalAssetRepository $assets,
		DigitalAssetVariantRepository $variants,
		AssetStorage $storage,
		?string $siteHost = null,
	): Response {
		$user = $this->security->getUser();
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			throw new AccessDeniedHttpException('Complete the MFA challenge before accessing private assets.');
		}
		if (null === $siteHost && !$user instanceof User && !$user instanceof GuestApiUser) {
			throw new AccessDeniedHttpException('A site-scoped API credential is required.');
		}
		if ($user instanceof GuestApiUser) {
			$domain = $this->sites::getCurrent()->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
			if (!is_string($domain) || 0 !== strcasecmp($user->getSiteHost(), $domain)) {
				throw new AccessDeniedHttpException('Guest API credential is scoped to another site.');
			}
		}
		if ($user instanceof User) {
			$user->setCurrentSite($this->sites::getCurrent());
		}

		return $this->files->__invoke($request, $publicToken, $variant, $assets, $variants, $this->sites, $storage);
	}
}
