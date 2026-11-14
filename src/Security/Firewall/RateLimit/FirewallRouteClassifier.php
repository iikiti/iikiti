<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\RateLimit;

/**
 * Maps a request path to the route group used by rate-limit and DoS policies.
 *
 * Order matters: more specific groups are checked before broader ones.
 */
final class FirewallRouteClassifier
{
	/** @var list<array{group:string,pattern:string}> */
	private const ROUTES = [
		['group' => 'mfa', 'pattern' => '#^/api/auth/mfa/#'],
		['group' => 'auth', 'pattern' => '#^/api/auth/#'],
		['group' => 'forms', 'pattern' => '#^/api/forms/#'],
		['group' => 'search', 'pattern' => '#^/api/(admin/search/|search)#'],
		['group' => 'admin', 'pattern' => '#^(/api/admin/|/admin)#'],
		['group' => 'api', 'pattern' => '#^/api#'],
	];

	public function classify(string $path): string
	{
		foreach (self::ROUTES as $route) {
			if (1 === preg_match($route['pattern'], $path)) {
				return $route['group'];
			}
		}

		return 'public';
	}
}
