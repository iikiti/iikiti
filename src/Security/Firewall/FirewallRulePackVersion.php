<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final class FirewallRulePackVersion
{
	public static function isValid(string $version): bool
	{
		return strlen($version) <= 64 && (bool) preg_match(
			'/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D',
			$version,
		);
	}

	public static function isOlderThan(string $candidate, string $current): bool
	{
		return version_compare($candidate, $current, '<');
	}
}
