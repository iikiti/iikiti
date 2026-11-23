<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\Extension\ConsentManagement\Service\ConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read/update the consent-management plugin configuration for the current
 * site (banner defaults, legal-framework override, consent version).
 */
#[AsController]
#[RequiresPlugin('consent-management')]
final class PrivacyConfigController extends AbstractController
{
	/** @var list<string> */
	private const ALLOWED_FRAMEWORKS = ['gdpr', 'ccpa', 'lgpd', 'pipeda', 'none'];

	public function __construct(private readonly EntityManagerInterface $entityManager)
	{
	}

	#[Route('/api/admin/privacy/config', name: 'api_admin_privacy_config', methods: ['GET'])]
	public function get(): JsonResponse
	{
		$this->denyAccessUnlessGranted('ROLE_ADMIN');

		$site = $this->requireCurrentSite();
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

		$site = $this->requireCurrentSite();
		$configuration = $site->getConfiguration();
		$existing = (array) $configuration->getPluginConfiguration('consent-management');
		try {
			$validated = $this->validateConfigPayload($data, $existing);
		} catch (\InvalidArgumentException $error) {
			return new JsonResponse(['error' => $error->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$configuration->setPluginConfiguration('consent-management', $validated);
		$site->setConfiguration($configuration);
		$this->entityManager->persist($site);
		$this->entityManager->flush();

		return new JsonResponse(['ok' => true]);
	}

	private function requireCurrentSite(): Site
	{
		if (!SiteRegistry::hasCurrent()) {
			throw new NotFoundHttpException('No active site.');
		}

		return SiteRegistry::getCurrent();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $existing
	 *
	 * @return array{version:int,default_categories:array<string,bool>,framework_override:?string}
	 */
	private function validateConfigPayload(array $data, array $existing): array
	{
		$rawVersion = $data['version'] ?? $existing['version'] ?? 1;
		$version = is_int($rawVersion) || (is_string($rawVersion) && ctype_digit($rawVersion))
			? (int) $rawVersion
			: 0;
		if ($version < 1) {
			throw new \InvalidArgumentException('Consent version must be a positive integer.');
		}

		$rawCategories = $data['default_categories'] ?? $existing['default_categories'] ?? ConsentService::DEFAULT_CATEGORIES;
		if (is_string($rawCategories)) {
			$decoded = json_decode($rawCategories, true);
			$rawCategories = is_array($decoded) ? $decoded : null;
		}
		if (!is_array($rawCategories)) {
			throw new \InvalidArgumentException('default_categories must be an object.');
		}
		$allowedKeys = array_keys(ConsentService::DEFAULT_CATEGORIES);
		foreach ($rawCategories as $key => $val) {
			if (!in_array((string) $key, $allowedKeys, true) || !is_bool($val)) {
				throw new \InvalidArgumentException('default_categories may only contain boolean values for necessary, analytics, and marketing.');
			}
		}
		$categories = [
			'necessary' => (bool) ($rawCategories['necessary'] ?? true),
			'analytics' => (bool) ($rawCategories['analytics'] ?? false),
			'marketing' => (bool) ($rawCategories['marketing'] ?? false),
		];

		$framework = $data['framework_override'] ?? ($existing['framework_override'] ?? null);
		if (null !== $framework) {
			if (!is_string($framework)) {
				throw new \InvalidArgumentException('framework_override must be a string or null.');
			}
			$framework = strtolower(trim($framework));
			if ('' === $framework) {
				$framework = null;
			} elseif (!in_array($framework, self::ALLOWED_FRAMEWORKS, true)) {
				throw new \InvalidArgumentException('framework_override must be one of gdpr, ccpa, lgpd, pipeda, or none.');
			}
		}

		return [
			'version' => $version,
			'default_categories' => $categories,
			'framework_override' => $framework,
		];
	}
}
