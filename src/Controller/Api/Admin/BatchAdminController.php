<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Batch\BatchAccessPolicy;
use iikiti\CMS\Batch\BatchManager;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\Batch\BatchEvent;
use iikiti\CMS\Entity\Batch\BatchItem;
use iikiti\CMS\Entity\Batch\BatchJob;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/batches', name: 'api_admin_batches_')]
final class BatchAdminController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(Request $request, EntityManagerInterface $entityManager, BatchAccessPolicy $access): JsonResponse
	{
		$user = $this->adminUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$qb = $entityManager->createQueryBuilder()->select('job')->from(BatchJob::class, 'job')->orderBy('job.createdAt', 'DESC')->setMaxResults(100);
		if (!$access->isGlobalAdmin($user)) {
			$siteIds = $access->getManageableSiteIds($user);
			if ([] === $siteIds) {
				return $this->json(['jobs' => []]);
			}
			$qb->andWhere('job.siteId IN (:siteIds)')->setParameter('siteIds', $siteIds);
		}
		if ($request->query->has('siteId')) {
			$siteId = $request->query->get('siteId');
			if (!$access->isGlobalAdmin($user) && !$access->canManageSite($user, is_scalar($siteId) ? (string) $siteId : null)) {
				return $this->forbidden();
			}
			$qb->andWhere('job.siteId = :siteId')->setParameter('siteId', $siteId);
		}
		$jobs = $qb->getQuery()->getResult();

		return $this->json(['jobs' => array_map(fn (BatchJob $job): array => $this->serializeJob($job), $jobs)]);
	}

	#[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
	public function show(int $id, EntityManagerInterface $entityManager, BatchAccessPolicy $access): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}

		return $this->json($this->serializeJob($job));
	}

	#[Route('/{id}/items', name: 'items', requirements: ['id' => '\\d+'], methods: ['GET'])]
	public function items(int $id, Request $request, EntityManagerInterface $entityManager, BatchAccessPolicy $access): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}
		$after = max(0, $request->query->getInt('after', 0));
		$limit = min(200, max(1, $request->query->getInt('limit', 50)));
		$items = $entityManager->getRepository(BatchItem::class)->createQueryBuilder('item')
			->andWhere('item.job = :job')->andWhere('item.id > :after')
			->setParameter('job', $job)->setParameter('after', $after)
			->orderBy('item.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

		return $this->json(['items' => array_map(static fn (BatchItem $item): array => [
			'id' => (string) $item->getId(),
			'key' => $item->getKey(),
			'type' => $item->getType(),
			'summary' => $item->getSummary(),
			'state' => $item->getState(),
			'attempts' => $item->getAttempts(),
			'error' => $item->getLastError(),
		], $items), 'nextAfter' => [] === $items ? null : (string) end($items)->getId()]);
	}

	#[Route('/{id}/events', name: 'events', requirements: ['id' => '\\d+'], methods: ['GET'])]
	public function events(int $id, Request $request, EntityManagerInterface $entityManager, BatchAccessPolicy $access, DatabaseTableNameResolver $tableNames): JsonResponse|StreamedResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}
		$mode = $request->query->get('mode', 'poll');
		if (!in_array($mode, ['poll', 'stream'], true)) {
			return $this->json(['error' => 'mode must be poll or stream.'], Response::HTTP_BAD_REQUEST);
		}
		$after = max(0, (int) ($request->headers->get('Last-Event-ID') ?? $request->query->get('after', 0)));
		if ('poll' === $mode) {
			return $this->json([
				'job' => $this->serializeJob($job),
				'events' => $this->readEvents($entityManager, $id, $after, min(500, max(1, $request->query->getInt('limit', 100))), $tableNames),
			]);
		}

		$connection = $entityManager->getConnection();
		$initialSnapshot = $this->serializeJob($job);
		$response = new StreamedResponse(function () use ($connection, $id, $after, $initialSnapshot, $tableNames): void {
			while (ob_get_level() > 0) {
				ob_end_flush();
			}
			$cursor = $after;
			$startedAt = microtime(true);
			$lastHeartbeat = $startedAt;
			echo "event: snapshot\ndata: ".json_encode($initialSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
			flush();
			while (!connection_aborted() && microtime(true) - $startedAt < 25) {
				$rows = $connection->fetchAllAssociative(
					$tableNames->qualifySql(' SELECT id, event_type, summary, details, created_at FROM batch_events WHERE batch_job_id = :job AND id > :cursor ORDER BY id ASC LIMIT 100'),
					['job' => $id, 'cursor' => $cursor],
				);
				foreach ($rows as $row) {
					$cursor = max($cursor, (int) $row['id']);
					$data = [
						'id' => (string) $row['id'],
						'type' => $row['event_type'],
						'summary' => $row['summary'],
						'details' => json_decode((string) $row['details'], true, 512, JSON_THROW_ON_ERROR),
						'createdAt' => (string) $row['created_at'],
					];
					echo 'id: '.$cursor."\nevent: update\ndata: ".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
				}
				if ([] !== $rows) {
					flush();
					$lastHeartbeat = microtime(true);
				} elseif (microtime(true) - $lastHeartbeat >= 10) {
					echo ": heartbeat\n\n";
					flush();
					$lastHeartbeat = microtime(true);
				}
				usleep(250000);
			}
		});
		$response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
		$response->headers->set('Cache-Control', 'no-cache, no-transform');
		$response->headers->set('X-Accel-Buffering', 'no');

		return $response;
	}

	#[Route('/{id}/cancel', name: 'cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
	public function cancel(int $id, EntityManagerInterface $entityManager, BatchAccessPolicy $access, BatchManager $batches): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}

		return $this->json(['cancelled' => $batches->cancel($id)]);
	}

	#[Route('/{id}/retry', name: 'retry', requirements: ['id' => '\\d+'], methods: ['POST'])]
	public function retry(int $id, EntityManagerInterface $entityManager, BatchAccessPolicy $access, BatchManager $batches): JsonResponse
	{
		$job = $entityManager->find(BatchJob::class, $id);
		if (!$job instanceof BatchJob || !$this->canAccess($job, $access)) {
			return $this->notFound();
		}
		if (!in_array($job->getStatus(), [BatchJob::STATUS_COMPLETED_WITH_ERRORS, BatchJob::STATUS_FAILED], true)) {
			return $this->json(['error' => 'Only completed or failed batches can be retried.'], Response::HTTP_CONFLICT);
		}

		return $this->json(['requeued' => $batches->retryFailed($id)]);
	}

	/** @return list<array<string,mixed>> */
	private function readEvents(EntityManagerInterface $entityManager, int|string $jobId, int $after, int $limit, DatabaseTableNameResolver $tableNames): array
	{
		$rows = $entityManager->getConnection()->fetchAllAssociative(
			$tableNames->qualifySql(' SELECT id, event_type, summary, details, created_at FROM batch_events WHERE batch_job_id = :job AND id > :cursor ORDER BY id ASC LIMIT '.$limit),
			['job' => $jobId, 'cursor' => $after],
		);

		return array_map(static fn (array $row): array => [
			'id' => (string) $row['id'],
			'type' => $row['event_type'],
			'summary' => $row['summary'],
			'details' => json_decode((string) $row['details'], true, 512, JSON_THROW_ON_ERROR),
			'createdAt' => (string) $row['created_at'],
		], $rows);
	}

	/** @return array<string,mixed> */
	private function serializeJob(BatchJob $job): array
	{
		return [
			'id' => (string) $job->getId(),
			'name' => $job->getName(),
			'taskKey' => $job->getTaskKey(),
			'siteId' => $job->getSiteId(),
			'status' => $job->getStatus(),
			'total' => $job->getTotalItems(),
			'terminal' => $job->getTerminalItems(),
			'percent' => $job->getProgressPercent(),
			'totalKnown' => $job->isTotalKnown(),
			'maxAttempts' => $job->getMaxAttempts(),
			'createdAt' => $job->getCreatedAt()->format(DATE_ATOM),
			'updatedAt' => $job->getUpdatedAt()->format(DATE_ATOM),
			'completedAt' => $job->getCompletedAt()?->format(DATE_ATOM),
		];
	}

	private function canAccess(BatchJob $job, BatchAccessPolicy $access): bool
	{
		$user = $this->adminUser();

		return null !== $user && $access->canAccessJob($user, $job);
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
