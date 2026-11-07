<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Calls the database-owning batch worker API from a headless Messenger worker. */
final readonly class BatchItemProcessor
{
	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%env(string:BATCH_PROCESS_TRIGGER_TOKEN)%')]
		private string $triggerToken,
		private BatchManager $manager,
		private RequestStack $requests,
	) {
	}

	public function process(int|string $itemId): void
	{
		$itemId = (string) $itemId;
		if ('api_internal_background_queue_run' === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
			$this->manager->processItem($itemId);

			return;
		}
		if ('' === $this->triggerToken || 1 !== preg_match('/^\d+$/D', $itemId)) {
			throw new \LogicException('A valid batch trigger credential and item ID are required.');
		}

		$this->api->request(
			'POST',
			'/api/internal/batches/items/'.$itemId.'/process',
			headers: ['X-BATCH-TRIGGER' => $this->triggerToken],
			timeout: 120.0,
		);
	}
}
