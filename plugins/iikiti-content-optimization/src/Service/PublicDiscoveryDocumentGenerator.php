<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class PublicDiscoveryDocumentGenerator
{
	private const MAX_SITEMAP_URLS = 50_000;
	private const SITEMAP_BATCH_SIZE = 1_000;
	private const CACHE_TTL_SECONDS = 300;
	private const AI_CRAWLERS = [
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-Web',
		'anthropic-ai',
		'PerplexityBot',
		'Google-Extended',
		'Bytespider',
		'CCBot',
		'Amazonbot',
		'Applebot-Extended',
		'Meta-ExternalAgent',
	];

	private readonly PageSeoSettingsNormalizer $pageSeoNormalizer;

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly PageRepository $pages,
		private readonly ContentOptimizationSettings $settings,
		private readonly EditorSettingsManager $editorSettings,
		private readonly ?ResponseHeaderResolver $responseHeaders = null,
		?PageSeoSettingsNormalizer $pageSeoNormalizer = null,
		private readonly ?ResponseHeaderStore $responseHeaderStore = null,
		private readonly ?ResponseHeaderPolicy $responseHeaderPolicy = null,
		#[Autowire(service: 'cache.rendered_fragments')]
		private readonly ?TagAwareCacheInterface $cache = null,
	) {
		$this->pageSeoNormalizer = $pageSeoNormalizer ?? new PageSeoSettingsNormalizer();
	}

	/** @return array{body:string,contentType:string} */
	public function robots(Request $request): array
	{
		[$site, $application] = $this->siteAndApplication();
		$scheme = $this->publicScheme($request);
		$origin = $this->origin($site, $scheme);

		return $this->rememberDocument($site, 'robots', $origin, function () use ($site, $application, $origin): array {
			$effective = $this->effectiveSettings($site, $application);
			$aiAccess = 'disallow' === ($effective['crawlers']['aiAccess'] ?? null) ? 'disallow' : 'allow';
			$lines = ['User-agent: *', 'Allow: /', ''];
			foreach (self::AI_CRAWLERS as $crawler) {
				$lines[] = 'User-agent: '.$crawler;
				$lines[] = ('allow' === $aiAccess ? 'Allow' : 'Disallow').': /';
				$lines[] = '';
			}

			if (true === ($effective['sitemap']['enabled'] ?? false)) {
				$lines[] = 'Sitemap: '.$origin.'/sitemap.xml';
			}

			return ['body' => rtrim(implode("\n", $lines))."\n", 'contentType' => 'text/plain; charset=UTF-8'];
		});
	}

	/** @return array{body:string,contentType:string} */
	public function sitemap(Request $request): array
	{
		[$site, $application] = $this->siteAndApplication();
		$effective = $this->effectiveSettings($site, $application);
		if (true !== ($effective['sitemap']['enabled'] ?? false)) {
			throw new NotFoundHttpException('Sitemap generation is disabled for this site.');
		}

		$scheme = $this->publicScheme($request);
		$origin = $this->origin($site, $scheme);

		return $this->rememberDocument($site, 'sitemap', $origin, function () use ($site, $application, $effective, $origin): array {
			$defaultIndex = 'noindex' === ($effective['seo']['defaultRobots']['index'] ?? null) ? 'noindex' : 'index';
			$robotsHeaderState = $this->prepareRobotsHeaderRules($application, $site);
			$urls = [];

			if ('unconditional_noindex' !== $robotsHeaderState['mode']) {
				$this->appendUrl(
					$urls,
					$origin.'/',
					$origin,
					$defaultIndex,
					$this->editorSettings->publishedSettings($site),
					$robotsHeaderState,
				);

				$siteId = (int) ($site->getId() ?? 0);
				$afterId = 0;
				do {
					$batch = $this->pages->findSitemapBatchForSite($siteId, $afterId, self::SITEMAP_BATCH_SIZE);
					foreach ($batch as $row) {
						$afterId = max($afterId, $row['id']);
						if (count($urls) >= self::MAX_SITEMAP_URLS) {
							break 2;
						}
						$slug = $row['slug'];
						if (!is_string($slug) || 1 !== preg_match('/^[\w\d_-]+$/D', $slug)) {
							continue;
						}
						$this->appendUrl(
							$urls,
							$origin.'/'.$slug,
							$origin,
							$defaultIndex,
							$row['settings'],
							$robotsHeaderState,
						);
					}
					$batchCount = count($batch);
				} while (self::SITEMAP_BATCH_SIZE === $batchCount && count($urls) < self::MAX_SITEMAP_URLS);
			}

			$entries = array_map(
				static fn (string $url): string => '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</loc></url>',
				$urls,
			);
			$body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			if ([] === $entries) {
				$body .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n";
			} else {
				$body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
				$body .= implode("\n", $entries)."\n";
				$body .= "</urlset>\n";
			}

			return ['body' => $body, 'contentType' => 'application/xml; charset=UTF-8'];
		});
	}

	/**
	 * @param callable():array{body:string,contentType:string} $builder
	 *
	 * @return array{body:string,contentType:string}
	 */
	private function rememberDocument(Site $site, string $docType, string $origin, callable $builder): array
	{
		$siteId = (int) ($site->getId() ?? 0);
		if (null === $this->cache || $siteId < 1) {
			return $builder();
		}

		$key = sprintf('iikiti_public_discovery_%s_%d_%s', $docType, $siteId, hash('sha256', $origin));

		try {
			return $this->cache->get($key, static function (ItemInterface $item) use ($builder): array {
				$document = $builder();
				$item->expiresAfter(self::CACHE_TTL_SECONDS);
				$item->tag([RenderedFragmentCache::CMS_DEPENDENCY_TAG]);

				return $document;
			});
		} catch (\Throwable) {
			// Cache failures must never break public discovery output.
		}

		return $builder();
	}

	/**
	 * @param list<string>                                                                                   $urls
	 * @param array<string, mixed>                                                                           $pageSettings
	 * @param array{mode:string,appRules:list<array<string,mixed>>,siteRules:list<array<string,mixed>>}     $robotsHeaderState
	 */
	private function appendUrl(
		array &$urls,
		string $url,
		string $origin,
		string $defaultIndex,
		array $pageSettings,
		array $robotsHeaderState,
	): void {
		try {
			$normalized = $this->pageSeoNormalizer->normalize($pageSettings);
		} catch (\InvalidArgumentException) {
			// Invalid legacy SEO settings fail closed to noindex.
			return;
		}

		$pageIndex = $normalized['iikiti_content_optimization_robots_index'] ?? '';
		$effectiveIndex = '' !== $pageIndex ? $pageIndex : $defaultIndex;
		if ('index' !== $effectiveIndex) {
			return;
		}

		$customCanonical = $normalized['iikiti_content_optimization_canonical_url'] ?? '';
		if ('' !== $customCanonical) {
			$resolvedCanonical = str_starts_with($customCanonical, '/') ? $origin.$customCanonical : $customCanonical;
			if ($resolvedCanonical !== $url) {
				// Advertise only pages that are self-canonical on this URL.
				return;
			}
		}

		if ($this->hasEffectiveNoindexHeader($url, $robotsHeaderState)) {
			return;
		}

		$urls[] = $url;
	}

	/**
	 * Preload and filter X-Robots-Tag rules once per sitemap generation so
	 * sites without conditional rules avoid per-URL policy evaluation.
	 *
	 * @return array{mode:string,appRules:list<array<string,mixed>>,siteRules:list<array<string,mixed>>}
	 */
	private function prepareRobotsHeaderRules(Application $application, Site $site): array
	{
		if (null === $this->responseHeaderStore || null === $this->responseHeaderPolicy) {
			return ['mode' => 'fallback', 'appRules' => [], 'siteRules' => []];
		}

		$filter = static fn (array $rules): array => array_values(array_filter(
			$rules,
			static fn (array $rule): bool => 'x-robots-tag' === strtolower((string) ($rule['name'] ?? '')),
		));

		$appRules = $filter($this->responseHeaderStore->getPolicy($application)['rules']);
		$siteRules = $filter($this->responseHeaderStore->getPolicy($site)['rules']);

		if ([] === $appRules && [] === $siteRules) {
			return ['mode' => 'none', 'appRules' => [], 'siteRules' => []];
		}

		$hasConditional = false;
		foreach ([...$appRules, ...$siteRules] as $rule) {
			if (isset($rule['conditions'])) {
				$hasConditional = true;
				break;
			}
		}

		if (!$hasConditional) {
			$winner = $this->responseHeaderPolicy->resolveValidated(
				$appRules,
				$siteRules,
				Request::create('https://localhost/', 'GET'),
				new Response('', Response::HTTP_OK),
			)['x-robots-tag'] ?? null;

			return [
				'mode' => $this->ruleSetsNoindex($winner) ? 'unconditional_noindex' : 'none',
				'appRules' => [],
				'siteRules' => [],
			];
		}

		return ['mode' => 'conditional', 'appRules' => $appRules, 'siteRules' => $siteRules];
	}

	/**
	 * @param array{mode:string,appRules:list<array<string,mixed>>,siteRules:list<array<string,mixed>>} $robotsHeaderState
	 */
	private function hasEffectiveNoindexHeader(string $url, array $robotsHeaderState): bool
	{
		if ('none' === $robotsHeaderState['mode']) {
			return false;
		}
		if ('unconditional_noindex' === $robotsHeaderState['mode']) {
			return true;
		}

		$request = Request::create($url, 'GET');
		$response = new Response('', Response::HTTP_OK);
		if ('conditional' === $robotsHeaderState['mode'] && null !== $this->responseHeaderPolicy) {
			$rule = $this->responseHeaderPolicy->resolveValidated(
				$robotsHeaderState['appRules'],
				$robotsHeaderState['siteRules'],
				$request,
				$response,
			)['x-robots-tag'] ?? null;

			return $this->ruleSetsNoindex($rule);
		}

		if (null === $this->responseHeaders) {
			return false;
		}
		$rule = $this->responseHeaders->resolve($request, $response)['rules']['x-robots-tag'] ?? null;

		return $this->ruleSetsNoindex($rule);
	}

	private function ruleSetsNoindex(mixed $rule): bool
	{
		if (!is_array($rule) || 'set' !== ($rule['action'] ?? null)) {
			return false;
		}

		foreach (preg_split('/[,;]/', (string) ($rule['value'] ?? '')) ?: [] as $directive) {
			$directive = preg_replace('/^[^:]+:\s*/', '', trim($directive)) ?? '';
			if ([] !== array_intersect(['noindex', 'none'], preg_split('/\s+/', strtolower($directive)) ?: [])) {
				return true;
			}
		}

		return false;
	}

	/** @return array{Site,Application} */
	private function siteAndApplication(): array
	{
		$site = SiteRegistry::getCurrent();
		if (!in_array(ContentOptimizationSettings::PLUGIN_SLUG, $site->getEnabledPlugins(), true)) {
			throw new NotFoundHttpException('Content Optimization is not active for this site.');
		}
		$application = $this->sites->getApplicationBySite($site);
		if (!$application instanceof Application) {
			throw new NotFoundHttpException('Site application not found.');
		}

		return [$site, $application];
	}

	/** @return array<string,mixed> */
	private function effectiveSettings(Site $site, Application $application): array
	{
		return $this->settings->resolve($this->settings->applicationDefaults($application), $this->settings->siteOverrides($site))['effective'];
	}

	private function origin(Site $site, string $scheme): string
	{
		$domain = $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
		$domainUrl = is_string($domain) ? 'https://'.$domain : '';
		$parsedDomain = '' !== $domainUrl ? parse_url($domainUrl) : false;
		$domainMatches = [];
		$validDomainSyntax = is_string($domain)
			&& 1 === preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/iD', $domain, $domainMatches);
		$validPort = !isset($domainMatches[1]) || false !== filter_var($domainMatches[1], FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1, 'max_range' => 65_535],
		]);
		if (
			!$validDomainSyntax ||
			!$validPort ||
			false === $parsedDomain ||
			false === filter_var($domainUrl, FILTER_VALIDATE_URL)
		) {
			throw new NotFoundHttpException('Site domain is invalid.');
		}

		return $scheme.'://'.$domain;
	}

	private function publicScheme(Request $request): string
	{
		$scheme = $request->getScheme();

		return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
	}
}
