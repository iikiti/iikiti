<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/** Headless scheduler client; due-schedule state remains inside the API runtime. */
final readonly class BatchScheduleRunner
{
	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%env(string:SCHEDULE_TRIGGER_TOKEN)%')]
		private string $triggerToken,
		private BatchScheduleManager $manager,
		private RequestStack $requests,
	) {
	}

	public function runDue(int $limit = 100): int
	{
		if ('api_internal_background_queue_run' === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
			return $this->manager->runDue($limit);
		}
		if ('' === trim($this->triggerToken)) {
			throw new \LogicException('SCHEDULE_TRIGGER_TOKEN must be configured for the headless scheduler.');
		}

		$data = $this->api->request(
			'POST',
			'/api/internal/schedules/run-due',
			body: ['limit' => max(1, min(500, $limit))],
			headers: ['X-SCHEDULE-TRIGGER' => $this->triggerToken],
			timeout: 55.0,
		);
		$count = $data['count'] ?? null;
		if (!is_int($count) || $count < 0) {
			throw new \UnexpectedValueException('The scheduler API returned an invalid run count.');
		}

		return $count;
	}
}
