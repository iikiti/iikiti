<?php

declare(strict_types=1);

namespace iikiti\CMS\Maintenance;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Headless trigger for a bounded database-owning maintenance tick. */
final readonly class BackgroundQueueApiClient
{
	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%env(string:BACKGROUND_QUEUE_TRIGGER_TOKEN)%')]
		private string $triggerToken,
	) {
	}

	public function run(): int
	{
		if ('' === trim($this->triggerToken)) {
			throw new \LogicException('BACKGROUND_QUEUE_TRIGGER_TOKEN must be configured for the queue trigger.');
		}

		$data = $this->api->request(
			'POST',
			'/api/internal/maintenance/run-background-queue',
			headers: ['X-BACKGROUND-QUEUE-TRIGGER' => $this->triggerToken],
			timeout: 15.0,
		);
		$count = $data['count'] ?? null;
		if (!is_int($count) || $count < 0) {
			throw new \UnexpectedValueException('The background queue API returned an invalid processed count.');
		}

		return $count;
	}
}
