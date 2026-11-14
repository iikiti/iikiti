<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Admin;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Guards `/api/site/{siteId}/...` firewall endpoints against cross-site access.
 *
 * The requested site id must match the request-host site resolved by {@see SiteRegistry}. A mismatch
 * is reported as not-found so callers cannot probe for the existence of other sites.
 */
final class FirewallSiteScope
{
	/** @param array<string,mixed> $uriVariables */
	public static function assertCurrent(array $uriVariables): Site
	{
		$site = SiteRegistry::getCurrent();
		if ((string) $site->getId() !== (string) ($uriVariables['siteId'] ?? '')) {
			throw new NotFoundHttpException('Site firewall resource not found.');
		}

		return $site;
	}
}
