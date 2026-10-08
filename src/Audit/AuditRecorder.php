<?php

namespace iikiti\CMS\Audit;

/**
 * Public API for recording audit events. Plugins and core code must use this
 * service rather than {@see AuditLogger} directly.
 *
 * Every recorded change requires a human-readable summary so the audit log can
 * be read at a glance. Use {@see record()} for a sub-action inside the current
 * request, and {@see openEvent()} / {@see closeEvent()} to group a whole
 * operation (for example a login) under one top-level entry.
 */
class AuditRecorder
{
	public function __construct(
		private readonly AuditLogger $logger,
	) {
	}

	/**
	 * Records a change. Within an open event it becomes a sub-event; otherwise
	 * it is written as its own top-level event.
	 *
	 * @param array<string,mixed>|null $beforeState
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $details    Technical detail for this change
	 */
	public function record(
		string $summary,
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $beforeState = null,
		?array $afterState = null,
		array $details = [],
	): void {
		$this->assertSummary($summary);

		$this->logger->log(
			$action,
			$objectType,
			$objectId,
			$beforeState,
			$afterState,
			'user',
			['summary' => $summary, 'details' => $details],
			true,
			$summary,
		);
	}

	/**
	 * Opens a top-level event grouping the changes recorded until
	 * {@see closeEvent()}. Does nothing if an event is already open.
	 *
	 * @param array<string,mixed> $details
	 */
	public function openEvent(string $action, string $summary, array $details = []): void
	{
		$this->assertSummary($summary);

		$this->logger->openEvent($action, $summary, ['details' => $details]);
	}

	/**
	 * Closes the open event. It is persisted only if it has sub-events.
	 */
	public function closeEvent(): void
	{
		$this->logger->closeEvent();
	}

	private function assertSummary(string $summary): void
	{
		if ('' === trim($summary)) {
			throw new \InvalidArgumentException('Audit events require a non-empty human-readable summary.');
		}
	}
}
