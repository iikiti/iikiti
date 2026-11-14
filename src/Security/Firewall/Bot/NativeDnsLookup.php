<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * DNS lookups via PHP's resolver. Lookups are synchronous and bounded by the caller
 * (FirewallFcrdnsVerifier caches results and caps lookups per request).
 */
final class NativeDnsLookup implements DnsLookupInterface
{
	public function reverseLookup(string $ip): ?string
	{
		$normalized = IpCidrNormalizer::normalizeIp($ip);
		if (null === $normalized) {
			return null;
		}

		$hostname = gethostbyaddr($normalized);
		if (false === $hostname || $hostname === $normalized || '' === $hostname) {
			return null;
		}

		return strtolower(rtrim($hostname, '.'));
	}

	/** @return list<string> */
	public function forwardLookup(string $hostname): array
	{
		$addresses = [];
		foreach ([DNS_A, DNS_AAAA] as $type) {
			$records = @dns_get_record($hostname, $type);
			if (!is_array($records)) {
				continue;
			}
			foreach ($records as $record) {
				$address = $record['ip'] ?? $record['ipv6'] ?? null;
				if (!is_string($address)) {
					continue;
				}
				$canonical = IpCidrNormalizer::normalizeIp($address);
				if (null !== $canonical) {
					$addresses[] = $canonical;
				}
			}
		}

		return array_values(array_unique($addresses));
	}
}
