<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class ResponseHeadersController
{
	public function __construct(
		private readonly Security $security,
		private readonly RoleHierarchyInterface $roles,
		private readonly WorkflowAccessPolicy $access,
		private readonly ApplicationRepository $applications,
		private readonly SiteRepository $sites,
		private readonly EntityManagerInterface $entityManager,
		private readonly ResponseHeaderStore $store,
		private readonly ResponseHeaderPolicy $policy,
	) {
	}

	#[Route('/api/admin/applications/{applicationId}/headers', name: 'api_admin_application_headers_get', methods: ['GET'])]
	#[Route('/api/site/{siteId}/headers', name: 'api_site_headers_get', methods: ['GET'])]
	public function get(Request $request): JsonResponse
	{
		$owner = $this->owner($request);

		return new JsonResponse($this->identity($owner) + $this->store->getPolicy($owner));
	}

	#[Route('/api/admin/applications/{applicationId}/headers', name: 'api_admin_application_headers_put', methods: ['PUT'])]
	#[Route('/api/site/{siteId}/headers', name: 'api_site_headers_put', methods: ['PUT'])]
	public function put(Request $request): JsonResponse
	{
		$owner = $this->owner($request);
		try {
			$data = $this->body($request);
			$rules = $this->rules($data, $owner instanceof Application);
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], 400);
		}
		$version = $this->store->setPolicy($owner, $rules);
		$this->entityManager->persist($owner);
		$this->entityManager->flush();

		return new JsonResponse($this->identity($owner) + ['version' => $version, 'rules' => $rules]);
	}

	#[Route('/api/admin/applications/{applicationId}/headers/preview', name: 'api_admin_application_headers_preview', methods: ['POST'])]
	#[Route('/api/site/{siteId}/headers/preview', name: 'api_site_headers_preview', methods: ['POST'])]
	public function preview(Request $request): JsonResponse
	{
		$owner = $this->owner($request);
		try {
			$data = $this->body($request);
			$rules = array_key_exists('rules', $data) ? $this->rules($data, $owner instanceof Application) : $this->store->getPolicy($owner)['rules'];
			[$hypothetical, $response] = $this->previewContext($data);
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], 400);
		}
		$applicationRules = $rules;
		$siteRules = [];
		if ($owner instanceof Site) {
			$application = $this->sites->getApplicationBySite($owner);
			if (!$application instanceof Application) {
				throw new NotFoundHttpException('Site application not found.');
			}
			$applicationRules = $this->store->getPolicy($application)['rules'];
			$siteRules = $rules;
		}

		return new JsonResponse(['rules' => (object) $this->policy->resolve($applicationRules, $siteRules, $hypothetical, $response)]);
	}

	private function owner(Request $request): Application|Site
	{
		$user = $this->security->getUser();
		if (!$user instanceof User) {
			throw new AccessDeniedHttpException('Authentication is required.');
		}
		$globalRoles = UserRoleManager::convertEnumsToStrings($user->getGlobalRoles());
		$globalAdmin = in_array('ROLE_ADMIN', $this->roles->getReachableRoleNames(array_values($globalRoles)), true);
		$applicationId = $request->attributes->get('applicationId');
		if (null !== $applicationId) {
			if (!$globalAdmin) {
				throw new AccessDeniedHttpException('Global administrator authority is required.');
			}
			if (!is_scalar($applicationId) || !ctype_digit((string) $applicationId) || (int) $applicationId < 1) {
				throw new NotFoundHttpException('Application not found.');
			}
			$application = $this->applications->find($applicationId, null, null, ['filterBySite' => false]);
			if (!$application instanceof Application) {
				throw new NotFoundHttpException('Application not found.');
			}

			return $application;
		}
		if (!SiteRegistry::isInitialized() || !SiteRegistry::hasCurrent()) {
			throw new NotFoundHttpException('Site not found.');
		}
		$site = SiteRegistry::getCurrent();
		$siteId = $request->attributes->get('siteId');
		if (!is_scalar($siteId) || (string) $site->getId() !== (string) $siteId) {
			throw new NotFoundHttpException('Site not found.');
		}
		if (!$globalAdmin && !$this->access->canManageSite($user, $site)) {
			throw new AccessDeniedHttpException('Site manager authority is required.');
		}

		return $site;
	}

	/** @return array<string,int|string|null> */
	private function identity(Application|Site $owner): array
	{
		return [$owner instanceof Application ? 'applicationId' : 'siteId' => $owner->getId()];
	}

	/** @return array<string,mixed> */
	private function body(Request $request): array
	{
		try {
			if (strlen($request->getContent()) > 131072) {
				throw new \InvalidArgumentException('Request body exceeds 131072 bytes.');
			}
			$object = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
			if (!$object instanceof \stdClass) {
				throw new \InvalidArgumentException('Request body must be a JSON object.');
			}
			if (property_exists($object, 'rules') && !is_array($object->rules)) {
				throw new \InvalidArgumentException('Rules must be a list.');
			}
			if (property_exists($object, 'request') && (!$object->request instanceof \stdClass || (property_exists($object->request, 'headers') && !$object->request->headers instanceof \stdClass))) {
				throw new \InvalidArgumentException('Preview request and headers must be objects.');
			}

			return json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Invalid JSON request body.', 0, $exception);
		}
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rules(array $data, bool $application): array
	{
		if (!isset($data['rules']) || !is_array($data['rules']) || !array_is_list($data['rules'])) {
			throw new \InvalidArgumentException('Request body must contain a rules list.');
		}

		return $this->policy->validateRules($data['rules'], $application);
	}

	/** @param array<string,mixed> $data
	 * @return array{Request,Response}
	 */
	private function previewContext(array $data): array
	{
		$context = $data['request'] ?? [];
		if (!is_array($context) || ([] !== $context && array_is_list($context))) {
			throw new \InvalidArgumentException('Preview request must be an object.');
		}
		$scheme = $context['scheme'] ?? 'https';
		$method = $context['method'] ?? 'GET';
		$path = $context['path'] ?? '/';
		$status = $data['status'] ?? 200;
		$headers = $context['headers'] ?? [];
		if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true) || !is_string($method) || !preg_match('/^[A-Z]{1,32}$/D', $method) ||
			!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7f?#]/', $path) ||
			!is_int($status) || $status < 100 || $status > 599 || !is_array($headers) || ([] !== $headers && array_is_list($headers))) {
			throw new \InvalidArgumentException('Invalid preview request or response status.');
		}
		$hypothetical = Request::create($scheme.'://localhost'.$path, $method);
		foreach ($headers as $name => $value) {
			if (!is_string($name) || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $name) || !is_string($value) ||
				preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('/auth|cookie|token|secret|api-key/i', $name)) {
				throw new \InvalidArgumentException('Invalid or sensitive preview request header.');
			}
			$hypothetical->headers->set($name, $value);
		}

		return [$hypothetical, new Response('', $status)];
	}
}
