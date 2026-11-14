<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Dos;

/**
 * Outcome of an expensive-route concurrency slot acquisition.
 *
 * Distinguishes a refused slot (cap reached, `exceeded`) from an unavailable counter store
 * (`acquired: false, exceeded: false`), which fails open so cache outages never block traffic.
 */
final readonly class FirewallConcurrencySlotResult
{
	public function __construct(
		public bool $acquired,
		public bool $exceeded,
		public ?string $slotKey,
	) {
	}

	public static function notApplicable(): self
	{
		return new self(false, false, null);
	}

	public static function exceededCap(): self
	{
		return new self(false, true, null);
	}

	public static function unavailable(): self
	{
		return new self(false, false, null);
	}

	public static function granted(string $slotKey): self
	{
		return new self(true, false, $slotKey);
	}
}
