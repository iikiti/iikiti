<?php

namespace iikiti\CMS\Service;

use Symfony\Contracts\Service\ResetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Per-request state controlling whether database query caching is active.
 *
 * Normal requests default to enabled. Batch processing (HTTP routes flagged
 * with a request attribute or console commands) can disable caching so that
 * every query hits the database. A runtime strategy override allows switching
 * strategies dynamically (e.g. driven by a database configuration value).
 */
class CacheState implements ResetInterface
{
	private bool $enabled;

	private ?string $overrideStrategy = null;

	public function __construct(
		#[Autowire('%iikiti_cache.enabled%')]
		bool $enabled = true,
	) {
		$this->enabled = $enabled;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function enable(): void
	{
		$this->enabled = true;
	}

	public function disable(): void
	{
		$this->enabled = false;
	}

	/**
	 * Override the active caching strategy for the current context.
	 */
	public function setOverrideStrategy(?string $strategy): void
	{
		$this->overrideStrategy = $strategy;
	}

	public function getOverrideStrategy(): ?string
	{
		return $this->overrideStrategy;
	}

	public function reset(): void
	{
		$this->enabled = true;
		$this->overrideStrategy = null;
	}
}
