<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use iikiti\CMS\Batch\BatchScheduleManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SchedulerController
{
	public function __construct(
		private BatchScheduleManager $schedules,
		#[Autowire('%env(string:SCHEDULE_TRIGGER_TOKEN)%')]
		private string $triggerToken,
	) {
	}

	#[Route('/api/internal/schedules/run-due', name: 'api_internal_scheduler_run_due', methods: ['POST'])]
	public function runDue(Request $request): JsonResponse
	{
		$providedToken = $request->headers->get('X-SCHEDULE-TRIGGER', '');
		if ('' === trim($this->triggerToken) || !hash_equals($this->triggerToken, $providedToken)) {
			return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
		}

		try {
			$data = $request->toArray();
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Invalid scheduler request.'], Response::HTTP_BAD_REQUEST);
		}
		$limit = $data['limit'] ?? 100;
		if (!is_int($limit)) {
			return new JsonResponse(['error' => 'Invalid scheduler limit.'], Response::HTTP_BAD_REQUEST);
		}

		return new JsonResponse(['count' => $this->schedules->runDue(max(1, min(500, $limit)))]);
	}
}
