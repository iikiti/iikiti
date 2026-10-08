<?php

namespace iikiti\CMS\Audit;

use iikiti\CMS\Entity\AuditLogEntry;

/**
 * Holds the open top-level audit event for the current request or command.
 *
 * Sub-events recorded while an event is open are buffered here and persisted
 * together with their parent when the event is closed. An event with no
 * sub-events is discarded, so requests that change nothing leave no row.
 */
class AuditContext
{
	private ?AuditLogEntry $parent = null;

	/** @var list<AuditLogEntry> */
	private array $pendingSubEvents = [];

	public function open(AuditLogEntry $parent): void
	{
		$this->parent = $parent;
		$this->pendingSubEvents = [];
	}

	public function isOpen(): bool
	{
		return null !== $this->parent;
	}

	public function getParent(): ?AuditLogEntry
	{
		return $this->parent;
	}

	public function addSubEvent(AuditLogEntry $subEvent): void
	{
		$this->pendingSubEvents[] = $subEvent;
	}

	/**
	 * @return list<AuditLogEntry>
	 */
	public function takeSubEvents(): array
	{
		$subEvents = $this->pendingSubEvents;
		$this->pendingSubEvents = [];

		return $subEvents;
	}

	/**
	 * Closes the open event and returns it with its buffered sub-events.
	 *
	 * @return array{0: AuditLogEntry|null, 1: list<AuditLogEntry>}
	 */
	public function close(): array
	{
		$parent = $this->parent;
		$subEvents = $this->takeSubEvents();
		$this->parent = null;

		return [$parent, $subEvents];
	}
}
