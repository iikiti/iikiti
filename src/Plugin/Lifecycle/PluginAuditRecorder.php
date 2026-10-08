<?php

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Plugin\PluginContext;

/**
 * Records plugin-originated changes in the audit log.
 *
 * Plugins receive a {@see PluginEvent} during lifecycle transitions. Calling
 * {@see record()} through this service stamps every audit entry with the
 * plugin slug and version, so changes can be traced to the plugin that made
 * them without the plugin repeating that context.
 */
class PluginAuditRecorder
{
	public function __construct(
		private readonly AuditRecorder $recorder,
	) {
	}

	/**
	 * @param array<string,mixed>|null $beforeState
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $details
	 */
	public function record(
		PluginContext $context,
		string $summary,
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $beforeState = null,
		?array $afterState = null,
		array $details = [],
	): void {
		$this->recorder->record(
			$summary,
			$action,
			$objectType,
			$objectId,
			$beforeState,
			$afterState,
			$details + ['plugin' => $context->slug, 'pluginVersion' => $context->version],
		);
	}
}
