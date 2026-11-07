<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/** Remote trigger client for database-owning digital asset processing. */
final readonly class DigitalAssetProcessorClient
{
	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%env(string:ASSET_PROCESS_TRIGGER_TOKEN)%')]
		private string $triggerToken,
		private DigitalAssetWorkflowManager $manager,
		private RequestStack $requests,
	) {
	}

	public function process(int|string $assetId): void
	{
		$assetId = (string) $assetId;
		if ('api_internal_background_queue_run' === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
			$this->manager->process($assetId);

			return;
		}
		if ('' === $this->triggerToken || 1 !== preg_match('/^\d+$/D', $assetId)) {
			throw new \LogicException('A valid digital asset trigger credential and ID are required.');
		}

		$this->api->request(
			'POST',
			'/api/internal/digital-assets/'.$assetId.'/process',
			headers: ['X-ASSET-PROCESS-TRIGGER' => $this->triggerToken],
			timeout: 120.0,
		);
	}
}
