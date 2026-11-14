<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Dos;

/**
 * Outcome of the early L7 structural guard. When blocked, `statusCode` is the response to return.
 */
final readonly class FirewallDosResult
{
	/** @param list<string> $findings Stable, sanitized finding codes; never raw header or body values. */
	public function __construct(
		public bool $blocked,
		public int $statusCode,
		public array $findings,
	) {
	}

	public static function allowed(): self
	{
		return new self(false, 200, []);
	}
}
