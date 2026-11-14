<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

/**
 * Narrow DNS seam so FCrDNS verification can be exercised without network access.
 */
interface DnsLookupInterface
{
	/** Returns the PTR hostname for an IP, or null when none exists. */
	public function reverseLookup(string $ip): ?string;

	/**
	 * Returns the A/AAAA addresses for a hostname in canonical textual form.
	 *
	 * @return list<string>
	 */
	public function forwardLookup(string $hostname): array;
}
