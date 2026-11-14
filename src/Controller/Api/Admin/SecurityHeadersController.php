<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use iikiti\CMS\Service\SecurityHeaderStore;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for per-site security header policies.
 *
 * Guarded by ROLE_ADMIN. The site id in the path must match the site resolved
 * from the request host, otherwise a 404 is returned.
 */
#[Route('/api/admin/sites/{siteId}/security/headers', name: 'api_admin_site_security_headers_')]
final class SecurityHeadersController extends AppController
{
	public function __construct(
		Security $security,
		ContainerInterface $container,
		private readonly SecurityHeaderStore $store,
		private readonly SecurityHeaderPolicy $policy,
		#[Autowire('%iikiti_security_headers%')]
		private readonly string $envMode,
	) {
		parent::__construct($security, $container);
	}

	#[Route('', name: 'get', methods: ['GET'])]
	public function get(Request $request): JsonResponse
	{
		$site = $this->resolveSite($request);
		$policy = $this->store->getPolicy($site);

		return $this->json([
			'siteId' => $site->getId(),
			'version' => $policy['version'],
			'headers' => $policy['headers'],
		]);
	}

	#[Route('', name: 'put', methods: ['PUT'])]
	public function put(Request $request): JsonResponse
	{
		$site = $this->resolveSite($request);
		$data = json_decode($request->getContent(), true);
		if (!is_array($data) || !isset($data['headers']) || !is_array($data['headers'])) {
			return $this->json(['error' => 'Request body must contain a headers list.'], 400);
		}

		$headers = [];
		foreach ($data['headers'] as $header) {
			if (!is_array($header) || !isset($header['name'], $header['value'])) {
				return $this->json(['error' => 'Each header needs name and value.'], 400);
			}
			$headers[] = [
				'name' => (string) $header['name'],
				'mode' => (string) ($header['mode'] ?? SecurityHeaderPolicy::SITE_MODE_DEFAULT),
				'value' => (string) $header['value'],
			];
		}

		$version = $this->store->setPolicy($site, $headers);
		$this->getDoctrineEntityManager()->persist($site);
		$this->getDoctrineEntityManager()->flush();

		return $this->json([
			'siteId' => $site->getId(),
			'version' => $version,
			'headers' => $headers,
		]);
	}

	#[Route('/test', name: 'test', methods: ['POST'])]
	public function test(Request $request): Response
	{
		$site = $this->resolveSite($request);
		$isSecure = $request->isSecure();
		$mode = $this->policy->effectiveMode(
			$this->policy->normalizeEnvMode($this->envMode),
			$this->getSiteMode($site),
			$isSecure,
			true,
		);
		$nonce = base64_encode(random_bytes(16));
		$headers = $this->policy->renderHeaders(
			$this->store->getPolicy($site)['headers'],
			$mode,
			$nonce,
		);

		$body = '<!DOCTYPE html><html><head><title>Security headers test</title></head>'.
			'<body><h1>Security header test</h1>'.
			'<script>console.log("inline script");</script>'.
			'<style>body{color:red;}</style>'.
			'</body></html>';

		$response = new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
		foreach ($headers as $name => $value) {
			$response->headers->set($name, $value);
		}
		$response->headers->set('X-Iikiti-CSP-Nonce', $nonce);

		return $response;
	}

	private function resolveSite(Request $request): Site
	{
		$siteId = $request->attributes->get('siteId');
		$current = SiteRegistry::getCurrent();
		if ((string) $current->getId() !== (string) $siteId) {
			throw new NotFoundHttpException('Site security headers not found.');
		}

		return $current;
	}

	private function getSiteMode(Site $site): string
	{
		$config = $site->getConfiguration();
		$mode = $config->get('security_headers_mode');
		if (!is_string($mode) || '' === $mode) {
			return SecurityHeaderPolicy::SITE_MODE_DEFAULT;
		}
		if (!in_array($mode, [SecurityHeaderPolicy::SITE_MODE_DEFAULT, SecurityHeaderPolicy::SITE_MODE_FORCED_OFF, SecurityHeaderPolicy::SITE_MODE_FORCED_ON], true)) {
			return SecurityHeaderPolicy::SITE_MODE_DEFAULT;
		}

		return $mode;
	}

	private function getDoctrineEntityManager(): EntityManagerInterface
	{
		return $this->container->get(EntityManagerInterface::class);
	}
}
