<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\Extension\ConsentManagement\Service\ConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read/update the consent-management plugin configuration for the current
 * site (banner defaults, legal-framework override, consent version).
 */
#[AsController]
final class PrivacyConfigController extends AbstractController
{
	public function __construct(private readonly EntityManagerInterface $entityManager)
	{
	}

	#[Route('/api/admin/privacy/config', name: 'api_admin_privacy_config', methods: ['GET'])]
	public function get(): JsonResponse
	{
		$this->denyAccessUnlessGranted('ROLE_ADMIN');

		$site = SiteRegistry::getCurrent();
		$configuration = $site->getConfiguration();
		$config = (array) $configuration->getPluginConfiguration('consent-management');

		return new JsonResponse(array_merge([
			'version' => 1,
			'default_categories' => ConsentService::DEFAULT_CATEGORIES,
			'framework_override' => null,
		], $config));
	}

	#[Route('/api/admin/privacy/config', name: 'api_admin_privacy_config_update', methods: ['PUT'])]
	public function update(Request $request): JsonResponse
	{
		$this->denyAccessUnlessGranted('ROLE_ADMIN');

		$data = json_decode($request->getContent(), true);
		if (!is_array($data)) {
			return new JsonResponse(['error' => 'Invalid JSON body.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$site = SiteRegistry::getCurrent();
		$configuration = $site->getConfiguration();
		$configuration->setPluginConfiguration('consent-management', $data);
		$site->setConfiguration($configuration);
		$this->entityManager->persist($site);
		$this->entityManager->flush();

		return new JsonResponse(['ok' => true]);
	}
}
