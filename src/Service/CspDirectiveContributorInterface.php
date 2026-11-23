<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes directive tokens (such as `connect-src` hosts or `report-uri`)
 * to default Content-Security-Policy headers in {@see SecurityHeaderPolicy}.
 */
#[AutoconfigureTag('iikiti.security.csp_directive_contributor')]
interface CspDirectiveContributorInterface
{
	/**
	 * Map of CSP directive name (e.g. `connect-src`, `report-uri`) to values.
	 *
	 * @return array<string, list<string>>
	 */
	public function getDirectives(string $environment): array;
}
