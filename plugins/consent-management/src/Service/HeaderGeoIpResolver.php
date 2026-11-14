<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default geo IP resolver: reads `X-Geo-Country` header then the
 * `CONSENT_DEFAULT_COUNTRY` environment variable.
 */
final class HeaderGeoIpResolver implements GeoIpResolver
{
	public function resolveCountry(Request $request): ?string
	{
		$country = $request->headers->get('X-Geo-Country');
		if (null !== $country && '' !== trim($country)) {
			return strtoupper(trim((string) $country));
		}

		$env = $_ENV['CONSENT_DEFAULT_COUNTRY'] ?? $_SERVER['CONSENT_DEFAULT_COUNTRY'] ?? getenv('CONSENT_DEFAULT_COUNTRY');
		if (false === $env || '' === trim($env)) {
			return null;
		}

		return strtoupper(trim((string) $env));
	}
}
