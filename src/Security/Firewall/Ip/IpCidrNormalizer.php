<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Canonicalizes IPv4/IPv6 addresses and CIDR ranges so that rule storage,
 * compiled matching, and request evaluation all agree on a single textual form.
 */
final class IpCidrNormalizer
{
	private const MAX_INPUT_LENGTH = 64;
	private const IPV4_MAX_PREFIX = 32;
	private const IPV6_MAX_PREFIX = 128;

	/**
	 * @return array{cidr:string,ip:string,prefix:int,version:int}
	 *
	 * @throws \InvalidArgumentException when the address or prefix is malformed
	 */
	public static function normalize(string $cidrOrIp): array
	{
		$input = self::sanitizeInput($cidrOrIp);
		[$ipPart, $prefixPart] = self::splitCidr($input);

		$version = self::detectVersion($ipPart);
		$maxPrefix = 4 === $version ? self::IPV4_MAX_PREFIX : self::IPV6_MAX_PREFIX;
		$prefix = null === $prefixPart ? $maxPrefix : self::parsePrefix($prefixPart, $maxPrefix);

		$packed = self::pack($ipPart);
		$masked = self::maskBytes($packed, $prefix);
		$canonicalIp = self::unpack($masked);

		return [
			'cidr' => $canonicalIp.'/'.$prefix,
			'ip' => $canonicalIp,
			'prefix' => $prefix,
			'version' => $version,
		];
	}

	/**
	 * Returns the canonical textual form of a single IP address, or null when invalid.
	 */
	public static function normalizeIp(string $ip): ?string
	{
		$trimmed = trim($ip);
		if ('' === $trimmed || strlen($trimmed) > self::MAX_INPUT_LENGTH) {
			return null;
		}

		$packed = @inet_pton($trimmed);
		if (false === $packed) {
			return null;
		}

		return inet_ntop($packed) ?: null;
	}

	/**
	 * Groups an address into its rate-limit/anomaly subnet, e.g. 192.0.2.0/24.
	 */
	public static function subnetKey(string $ip, int $v4Prefix = 24, int $v6Prefix = 64): ?string
	{
		$normalizedIp = self::normalizeIp($ip);
		if (null === $normalizedIp) {
			return null;
		}

		$version = str_contains($normalizedIp, ':') ? 6 : 4;
		$prefix = 6 === $version ? $v6Prefix : $v4Prefix;

		return self::normalize($normalizedIp.'/'.$prefix)['cidr'];
	}

	/**
	 * Returns true when the client IP falls inside the given IP or CIDR range.
	 */
	public static function matches(string $ip, string $cidr): bool
	{
		$normalizedIp = self::normalizeIp($ip);
		if (null === $normalizedIp) {
			return false;
		}

		return IpUtils::checkIp($normalizedIp, $cidr);
	}

	private static function sanitizeInput(string $value): string
	{
		// Reject control characters before trimming: trim() silently strips NUL bytes.
		if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
			throw new \InvalidArgumentException('IP or CIDR value contains control characters.');
		}

		$trimmed = trim($value);
		if ('' === $trimmed || strlen($trimmed) > self::MAX_INPUT_LENGTH || str_contains($trimmed, ' ')) {
			throw new \InvalidArgumentException('IP or CIDR value is empty or contains invalid characters.');
		}

		return $trimmed;
	}

	/** @return array{0:string,1:?string} */
	private static function splitCidr(string $input): array
	{
		$parts = explode('/', $input);
		if (count($parts) > 2) {
			throw new \InvalidArgumentException('CIDR value contains more than one "/".');
		}

		return [$parts[0], $parts[1] ?? null];
	}

	private static function detectVersion(string $ip): int
	{
		if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return 4;
		}
		if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return 6;
		}

		throw new \InvalidArgumentException('Invalid IP address.');
	}

	private static function parsePrefix(string $prefixPart, int $maxPrefix): int
	{
		// Reject signs, whitespace, and leading zeros such as "08" to keep values unambiguous.
		if (!preg_match('/^(0|[1-9][0-9]{0,2})$/D', $prefixPart)) {
			throw new \InvalidArgumentException('CIDR prefix must be an unsigned integer without leading zeros.');
		}

		$prefix = (int) $prefixPart;
		if ($prefix > $maxPrefix) {
			throw new \InvalidArgumentException(sprintf('CIDR prefix must be between 0 and %d.', $maxPrefix));
		}

		return $prefix;
	}

	private static function pack(string $ip): string
	{
		$packed = @inet_pton($ip);
		if (false === $packed) {
			throw new \InvalidArgumentException('Invalid IP address.');
		}

		return $packed;
	}

	private static function unpack(string $packed): string
	{
		$text = inet_ntop($packed);
		if (false === $text) {
			throw new \InvalidArgumentException('Invalid IP address.');
		}

		return $text;
	}

	/** Zeroes every bit after the first $prefix bits. */
	private static function maskBytes(string $packed, int $prefix): string
	{
		$fullBytes = intdiv($prefix, 8);
		$remainderBits = $prefix % 8;
		$result = substr($packed, 0, $fullBytes);

		if ($remainderBits > 0) {
			$mask = (0xFF << (8 - $remainderBits)) & 0xFF;
			$result .= chr(ord($packed[$fullBytes]) & $mask);
		}

		return str_pad($result, strlen($packed), "\0");
	}
}
