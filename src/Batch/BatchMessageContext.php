<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

final readonly class BatchMessageContext
{
	public function __construct(
		public int|string $batchId,
		public int|string $itemId,
		public string $idempotencyKey,
		private BatchManager $manager,
	) {
	}

	/** @param array<string,scalar|null> $details */
	public function report(string $summary, array $details = []): void
	{
		$this->manager->report($this->batchId, 'message', $summary, $details);
	}

	public function isCancellationRequested(): bool
	{
		return $this->manager->isCancellationRequested($this->batchId);
	}
}
