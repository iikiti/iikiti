<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the visitor's country (ISO 3166-1 alpha-2) for geo-targeted
 * legal-framework selection.
 *
 * The default implementation reads the `X-Geo-Country` request header (set by
 * a reverse proxy / CDN) falling back to the `CONSENT_DEFAULT_COUNTRY`
 * environment variable. Implementations may integrate MaxMind or a commercial
 * geo IP database instead.
 */
interface GeoIpResolver
{
	/**
	 * @return string|null ISO 3166-1 alpha-2 country code, or null when unknown.
	 */
	public function resolveCountry(Request $request): ?string;
}
