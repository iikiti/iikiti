<?php

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Plugin\PluginContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event payload for plugin lifecycle transitions.
 */
class PluginEvent extends Event
{
	public function __construct(
		private readonly PluginContext $context,
		private readonly string $eventName = '',
		private readonly ?string $fromVersion = null,
	) {
	}

	public function getContext(): PluginContext
	{
		return $this->context;
	}

	public function getEventName(): string
	{
		return $this->eventName;
	}

	public function getFromVersion(): ?string
	{
		return $this->fromVersion;
	}

	public function getSlug(): string
	{
		return $this->context->slug;
	}

	public function getVersion(): string
	{
		return $this->context->version;
	}

	public function getSiteId(): ?string
	{
		return $this->context->siteId;
	}

	/**
	 * Records a change made by this plugin. Use this from lifecycle listeners
	 * instead of calling AuditRecorder directly so the entry is attributed to
	 * the plugin slug and version.
	 *
	 * @param array<string,mixed>|null $beforeState
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $details
	 */
	public function recordChange(
		PluginAuditRecorder $auditRecorder,
		string $summary,
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $beforeState = null,
		?array $afterState = null,
		array $details = [],
	): void {
		$auditRecorder->record($this->context, $summary, $action, $objectType, $objectId, $beforeState, $afterState, $details);
	}
}
