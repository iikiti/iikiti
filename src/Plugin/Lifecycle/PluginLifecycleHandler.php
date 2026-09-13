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
	) {
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
