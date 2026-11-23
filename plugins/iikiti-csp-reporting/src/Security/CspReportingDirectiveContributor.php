<?php

declare(strict_types=1);

namespace iikiti\Extension\CspReporting\Security;

use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Service\CspDirectiveContributorInterface;

/**
 * Contributes the default `report-uri` directive when `iikiti-csp-reporting` is
 * enabled for the current application runtime.
 */
final class CspReportingDirectiveContributor implements CspDirectiveContributorInterface
{
	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function getDirectives(string $environment): array
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-csp-reporting')) {
			return [];
		}

		return [
			'report-uri' => ['/api/internal/csp-reports'],
		];
	}
}
