<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use iikiti\CMS\Service\DigitalAssetWorkflowManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DigitalAssetWorkerController
{
	public function __construct(
		private DigitalAssetWorkflowManager $assets,
		#[Autowire('%env(string:ASSET_PROCESS_TRIGGER_TOKEN)%')]
		private string $triggerToken,
	) {
	}

	#[Route('/api/internal/digital-assets/{id}/process', name: 'api_internal_digital_asset_process', requirements: ['id' => '\\d+'], methods: ['POST'])]
	public function process(Request $request, string $id): JsonResponse
	{
		$providedToken = $request->headers->get('X-ASSET-PROCESS-TRIGGER', '');
		if ('' === trim($this->triggerToken) || !hash_equals($this->triggerToken, $providedToken)) {
			return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
		}

		$this->assets->process($id);

		return new JsonResponse(['processed' => true]);
	}
}
