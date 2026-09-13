<?php

namespace iikiti\CMS\Plugin;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Rebuilds the compiled Symfony container after plugin files change.
 *
 * Enabling, disabling, installing or removing a plugin changes which bundles
 * are registered, so the container must be recompiled. Rebuilds are scheduled
 * and flushed once at the end of a batch so multi-site operations do not trigger
 * one rebuild per site.
 */
class PluginContainerRebuilder
{
	private bool $scheduled = false;

	private bool $lastRebuildSuccessful = true;

	public function __construct(
		#[Autowire('%kernel.project_dir%')]
		private readonly string $projectDir,
		#[Autowire('%kernel.environment%')]
		private readonly string $environment,
		#[Autowire('%iikiti.plugin.auto_rebuild%')]
		private readonly bool $enabled = true,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function schedule(): void
	{
		$this->scheduled = true;
	}

	/**
	 * Run a single cache rebuild if one has been scheduled.
	 */
	public function flush(): bool
	{
		if (!$this->scheduled) {
			return true;
		}

		$this->scheduled = false;

		return $this->rebuild();
	}

	/**
	 * Whether the most recent rebuild (if any) completed successfully.
	 */
	public function wasLastRebuildSuccessful(): bool
	{
		return $this->lastRebuildSuccessful;
	}

	public function rebuild(): bool
	{
		$this->lastRebuildSuccessful = true;

		if (!$this->enabled) {
			return false;
		}

		$console = $this->projectDir.'/bin/console';
		if (!is_file($console)) {
			$this->lastRebuildSuccessful = false;

			return false;
		}

		$process = new Process([PHP_BINARY, $console, 'cache:clear', '--no-interaction', '--env='.$this->environment]);
		$process->setTimeout(300);
		$process->run();

		if (!$process->isSuccessful()) {
			$this->lastRebuildSuccessful = false;
			$this->logger?->error('Plugin container rebuild failed.', [
				'output' => $process->getErrorOutput(),
			]);

			return false;
		}

		return true;
	}
}
