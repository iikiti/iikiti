<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use iikiti\CMS\Batch\BatchManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BatchWorkerController
{
	public function __construct(
		private BatchManager $batches,
		#[Autowire('%env(string:BATCH_PROCESS_TRIGGER_TOKEN)%')]
		private string $triggerToken,
	) {
	}

	#[Route('/api/internal/batches/items/{id}/process', name: 'api_internal_batch_item_process', requirements: ['id' => '\\d+'], methods: ['POST'])]
	public function process(Request $request, string $id): JsonResponse
	{
		$providedToken = $request->headers->get('X-BATCH-TRIGGER', '');
		if ('' === trim($this->triggerToken) || !hash_equals($this->triggerToken, $providedToken)) {
			return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
		}

		$this->batches->processItem($id);

		return new JsonResponse(['processed' => true]);
	}
}
