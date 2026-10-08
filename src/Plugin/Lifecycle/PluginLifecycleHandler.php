<?php

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Plugin\PluginContext;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches plugin lifecycle events and invokes hooks on plugin bundles.
 *
 * Failures inside a single hook are logged and swallowed so one misbehaving
 * plugin cannot abort a batch operation across all sites.
 */
class PluginLifecycleHandler
{
	public function __construct(
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?PluginAuditRecorder $auditRecorder = null,
	) {
	}

	/**
	 * Records the lifecycle transition itself so installs, updates and removals
	 * appear in the audit log attributed to the plugin.
	 */
	private function auditLifecycle(string $eventName, PluginContext $context, ?string $fromVersion): void
	{
		if (null === $this->auditRecorder) {
			return;
		}

		$summary = match ($eventName) {
			PluginEvents::INSTALL => sprintf('Installed plugin %s %s', $context->slug, $context->version),
			PluginEvents::ACTIVATE => sprintf('Enabled plugin %s', $context->slug),
			PluginEvents::DEACTIVATE => sprintf('Disabled plugin %s', $context->slug),
			PluginEvents::UPDATE => sprintf('Updated plugin %s from %s to %s', $context->slug, $fromVersion ?? '?', $context->version),
			PluginEvents::UNINSTALL => sprintf('Removed plugin %s', $context->slug),
			default => sprintf('Plugin %s event %s', $context->slug, $eventName),
		};

		$this->auditRecorder->record(
			$context,
			$summary,
			$this->actionFor($eventName),
			'Plugin',
			null,
			null,
			null,
			['event' => $eventName, 'fromVersion' => $fromVersion],
		);
	}

	private function actionFor(string $eventName): string
	{
		return match ($eventName) {
			PluginEvents::INSTALL => 'installed_plugin',
			PluginEvents::ACTIVATE => 'enabled_plugin',
			PluginEvents::DEACTIVATE => 'disabled_plugin',
			PluginEvents::UPDATE => 'updated_configuration',
			PluginEvents::UNINSTALL => 'removed_plugin',
			default => 'updated_configuration',
		};
	}

	public function install(PluginContext $context, ?object $hookTarget = null): void
	{
		$this->dispatch(PluginEvents::INSTALL, $context, $hookTarget, 'onPluginInstall');
	}

	public function activate(PluginContext $context, ?object $hookTarget = null): void
	{
		$this->dispatch(PluginEvents::ACTIVATE, $context, $hookTarget, 'onPluginActivate');
	}

	public function deactivate(PluginContext $context, ?object $hookTarget = null): void
	{
		$this->dispatch(PluginEvents::DEACTIVATE, $context, $hookTarget, 'onPluginDeactivate');
	}

	public function update(PluginContext $context, string $fromVersion, ?object $hookTarget = null): void
	{
		$this->dispatch(PluginEvents::UPDATE, $context, $hookTarget, 'onPluginUpdate', $fromVersion);
	}

	public function uninstall(PluginContext $context, ?object $hookTarget = null): void
	{
		$this->dispatch(PluginEvents::UNINSTALL, $context, $hookTarget, 'onPluginUninstall');
	}

	private function dispatch(
		string $eventName,
		PluginContext $context,
		?object $hookTarget,
		string $hookMethod,
		?string $fromVersion = null,
	): void {
		$this->eventDispatcher->dispatch(new PluginEvent($context, $eventName, $fromVersion), $eventName);
		$this->auditLifecycle($eventName, $context, $fromVersion);

		if (null === $hookTarget || !method_exists($hookTarget, $hookMethod)) {
			return;
		}

		try {
			if ('onPluginUpdate' === $hookMethod) {
				$hookTarget->{$hookMethod}($context, $fromVersion ?? '');
			} else {
				$hookTarget->{$hookMethod}($context);
			}
		} catch (\Throwable $exception) {
			$this->logger?->error('Plugin lifecycle hook failed.', [
				'plugin' => $context->slug,
				'hook' => $hookMethod,
				'exception' => $exception,
			]);
		}
	}
}
