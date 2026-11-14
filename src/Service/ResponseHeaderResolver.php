<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResponseHeaderResolver
{
	public function __construct(
		private EntityManagerInterface $em,
		private ResponseHeaderStore $store,
		private ResponseHeaderPolicy $policy,
		#[Autowire('%iikiti_default_application_id%')] private int $defaultApplicationId,
	) {
	}

	/** @return array{rules:array<string,array<string,mixed>>,siteMode:string,vary:list<string>} */
	public function resolve(Request $request, Response $response, bool $useCurrentSite = true): array
	{
		/** @var SiteRepository $sites */
		$sites = $this->em->getRepository(Site::class);
		$site = $useCurrentSite && SiteRegistry::isInitialized() && SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		if (null === $site) {
			$matches = $sites->findByDomain($request->getHost());
			$site = [] === $matches ? null : end($matches);
		}
		$application = null === $site ? null : $sites->getApplicationBySite($site);
		if (null === $application && $this->defaultApplicationId > 0) {
			$application = $this->em->getRepository(Application::class)->find($this->defaultApplicationId);
			$site = null;
		}
		if (!$application instanceof Application) {
			throw new \RuntimeException('No application resolved for response headers; configure a valid IIKITI_DEFAULT_APPLICATION_ID.');
		}
		$mode = $site?->getConfiguration()->get('security_headers_mode');
		if (!is_string($mode) || !in_array($mode, ['default', 'forced_off', 'forced_on'], true)) {
			$mode = 'default';
		}
		$appRules = $this->store->getPolicy($application)['rules'];
		$siteRules = null === $site ? [] : $this->store->getPolicy($site)['rules'];
		$rules = $this->policy->resolve($appRules, $siteRules, $request, $response);

		return ['rules' => $rules, 'siteMode' => $mode, 'vary' => self::varyForRules($appRules, $siteRules)];
	}

	/**
	 * @param list<array<string,mixed>> $appRules
	 * @param list<array<string,mixed>> $siteRules
	 *
	 * @return list<string>
	 */
	public static function varyForRules(array $appRules, array $siteRules): array
	{
		$vary = [];
		$collect = static function (array $node) use (&$collect, &$vary): void {
			if (($node['field'] ?? null) === 'header' && is_string($node['name'] ?? null)) {
				$vary[$node['name']] = true;
			}
			foreach ($node as $value) {
				if (is_array($value)) {
					$collect($value);
				}
			}
		};
		$collect([$appRules, $siteRules]);

		return array_keys($vary);
	}
}
