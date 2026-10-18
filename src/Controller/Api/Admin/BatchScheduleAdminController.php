<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\BatchAccessPolicy;
use iikiti\CMS\Batch\ScheduledTaskProviderRegistry;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Batch\BatchSchedule;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/batches/schedules', name: 'api_admin_batch_schedules_')]
final class BatchScheduleAdminController extends AppController
{
	#[Route('/providers', name: 'providers', methods: ['GET'])]
	public function providers(ScheduledTaskProviderRegistry $providers, BatchAccessPolicy $access, SiteRepository $sites): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$siteIds = $access->getManageableSiteIds($user);
		$definitions = array_values(array_filter($providers->describe(), fn (array $provider): bool =>
			'global' === $provider['scope'] ? $access->isGlobalAdmin($user) : [] !== $siteIds
		));
		$manageableSites = [];
		foreach ($siteIds as $siteId) {
			$site = $sites->find($siteId);
			if ($site instanceof Site) {
				$properties = $site->getProperties();
				$manageableSites[] = ['id' => (string) $siteId, 'name' => $properties->get('name')?->getValue() ?? $properties->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site'];
			}
		}

		return $this->json(['providers' => $definitions, 'sites' => $manageableSites]);
	}

	#[Route('', name: 'index', methods: ['GET'])]
	public function index(EntityManagerInterface $entityManager, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$schedules = $entityManager->getRepository(BatchSchedule::class)->findBy([], ['nextRunAt' => 'ASC'], 200);
		$schedules = array_values(array_filter($schedules, static fn (BatchSchedule $schedule): bool => $access->canAccessSchedule($user, $schedule)));

		return $this->json(['schedules' => array_map(self::serializeSchedule(...), $schedules)]);
	}

	#[Route('', name: 'create', methods: ['POST'])]
	public function create(Request $request, EntityManagerInterface $entityManager, ScheduledTaskProviderRegistry $providers, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		try {
			$data = $request->toArray();
			$provider = $providers->get((string) ($data['providerKey'] ?? ''));
			$scope = $provider->getScope();
			$siteId = 'site' === $scope && isset($data['siteId']) ? (string) $data['siteId'] : null;
			if (!$access->canManageScope($user, $scope, $siteId)) {
				return $this->forbidden();
			}
			$options = $provider->validateOptions(is_array($data['options'] ?? null) ? $data['options'] : []);
			$schedule = new BatchSchedule(
				(string) ($data['name'] ?? ''),
				$provider->getKey(),
				(string) ($data['cronExpression'] ?? ''),
				(string) ($data['timezone'] ?? 'UTC'),
				$scope,
				$siteId,
				$options,
				(bool) ($data['enabled'] ?? true),
				$provider->allowsOverlap() && (bool) ($data['allowOverlap'] ?? false),
				$user->getId(),
			);
			$entityManager->persist($schedule);
			$entityManager->flush();

			return $this->json(self::serializeSchedule($schedule), Response::HTTP_CREATED);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (\Throwable) {
			return $this->json(['error' => 'Invalid schedule configuration.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
	}

	#[Route('/{id}', name: 'update', requirements: ['id' => '\\d+'], methods: ['PUT'])]
	public function update(int $id, Request $request, EntityManagerInterface $entityManager, ScheduledTaskProviderRegistry $providers, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		$schedule = $entityManager->find(BatchSchedule::class, $id);
		if (null === $user || !$schedule instanceof BatchSchedule) {
			return $this->notFound();
		}
		if (!$access->canAccessSchedule($user, $schedule)) {
			return $this->notFound();
		}
		try {
			$data = $request->toArray();
			$provider = $providers->get((string) ($data['providerKey'] ?? $schedule->getProviderKey()));
			$scope = $provider->getScope();
			$siteId = 'site' === $scope && isset($data['siteId']) ? (string) $data['siteId'] : ('site' === $scope ? $schedule->getSiteId() : null);
			if (!$access->canManageScope($user, $scope, $siteId)) {
				return $this->forbidden();
			}
			$options = $provider->validateOptions(is_array($data['options'] ?? null) ? $data['options'] : []);
			$schedule->update(
				(string) ($data['name'] ?? $schedule->getName()),
				$provider->getKey(),
				(string) ($data['cronExpression'] ?? $schedule->getCronExpression()),
				(string) ($data['timezone'] ?? $schedule->getTimezone()),
				$scope,
				$siteId,
				$options,
				(bool) ($data['enabled'] ?? $schedule->isEnabled()),
				$provider->allowsOverlap() && (bool) ($data['allowOverlap'] ?? false),
			);
			$entityManager->flush();

			return $this->json(self::serializeSchedule($schedule));
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (\Throwable) {
			return $this->json(['error' => 'Invalid schedule configuration.'], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
	}

	#[Route('/{id}/enabled', name: 'enabled', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
	public function enabled(int $id, Request $request, EntityManagerInterface $entityManager, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		$schedule = $entityManager->find(BatchSchedule::class, $id);
		if (null === $user || !$schedule instanceof BatchSchedule || !$access->canAccessSchedule($user, $schedule)) {
			return $this->notFound();
		}
		$data = $request->toArray();
		$schedule->update($schedule->getName(), $schedule->getProviderKey(), $schedule->getCronExpression(), $schedule->getTimezone(), $schedule->getScope(), $schedule->getSiteId(), $schedule->getOptions(), (bool) ($data['enabled'] ?? false), $schedule->allowsOverlap());
		$entityManager->flush();

		return $this->json(self::serializeSchedule($schedule));
	}

	/** @return array<string,mixed> */
	private static function serializeSchedule(BatchSchedule $schedule): array
	{
		return [
			'id' => (string) $schedule->getId(),
			'name' => $schedule->getName(),
			'providerKey' => $schedule->getProviderKey(),
			'cronExpression' => $schedule->getCronExpression(),
			'timezone' => $schedule->getTimezone(),
			'scope' => $schedule->getScope(),
			'siteId' => null === $schedule->getSiteId() ? null : (string) $schedule->getSiteId(),
			'options' => $schedule->getOptions(),
			'enabled' => $schedule->isEnabled(),
			'allowOverlap' => $schedule->allowsOverlap(),
			'lastRunAt' => $schedule->getLastRunAt()?->format(DATE_ATOM),
			'nextRunAt' => $schedule->getNextRunAt()->format(DATE_ATOM),
		];
	}

	private function adminUser(): ?User
	{
		$user = $this->getUser();

		return $user instanceof User && $this->isGranted('ROLE_ADMIN') ? $user : null;
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}

	private function notFound(): JsonResponse
	{
		return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
	}
}
