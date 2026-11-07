<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BackgroundQueueController
{
	public function __construct(
		private BackgroundQueueRunner $runner,
		#[Autowire('%env(string:BACKGROUND_QUEUE_TRIGGER_TOKEN)%')]
		private string $triggerToken,
	) {
	}

	#[Route('/api/internal/maintenance/run-background-queue', name: 'api_internal_background_queue_run', methods: ['POST'])]
	public function run(Request $request): JsonResponse
	{
		$providedToken = $request->headers->get('X-BACKGROUND-QUEUE-TRIGGER', '');
		if ('' === trim($this->triggerToken) || !hash_equals($this->triggerToken, $providedToken)) {
			return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
		}

		return new JsonResponse(['count' => $this->runner->run()]);
	}
}
