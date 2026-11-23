<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';

final class SeoMetadataResolverTest extends TestCase
{
	private SeoMetadataResolver $resolver;
	private ContentOptimizationSettings $settings;

	protected function setUp(): void
	{
		$this->settings = new ContentOptimizationSettings();
		$this->resolver = new SeoMetadataResolver($this->settings, new PageSeoSettingsNormalizer());
	}

	public function testResolvesApplicationSiteAndPagePrecedence(): void
	{
		$defaults = $this->settings->defaults();
		$defaults['seo'] = [
			'titleTemplate' => '{pageTitle} - {siteName}',
			'defaultDescription' => 'Application description',
			'defaultSocialImage' => 'https://cdn.example.test/application.png',
			'defaultRobots' => ['index' => 'index', 'follow' => 'follow'],
		];
		$siteOverrides = ['seo' => [
			'defaultDescription' => 'Site description',
			'defaultSocialImage' => 'https://cdn.example.test/site.png',
			'defaultRobots' => ['index' => 'noindex'],
		]];
		$pageSettings = [
			'iikiti_content_optimization_title' => 'Page SEO title',
			'iikiti_content_optimization_og_title' => 'Social title',
			'iikiti_content_optimization_og_type' => 'article',
			'iikiti_content_optimization_og_image' => 'https://cdn.example.test/page.png',
			'iikiti_content_optimization_twitter_title' => 'Card title',
			'iikiti_content_optimization_twitter_card' => 'summary',
		];

		$metadata = $this->resolver->resolve(
			$defaults,
			$siteOverrides,
			$pageSettings,
			'Page heading',
			'https://www.example.test/articles/example',
			'Example Site',
		);

		self::assertSame('Page SEO title', $metadata['title']);
		self::assertSame('Site description', $metadata['description']);
		self::assertSame(['index' => 'noindex', 'follow' => 'follow'], $metadata['robots']);
		self::assertSame('Social title', $metadata['openGraph']['title']);
		self::assertSame('Site description', $metadata['openGraph']['description']);
		self::assertSame('Example Site', $metadata['openGraph']['siteName']);
		self::assertSame('article', $metadata['openGraph']['type']);
		self::assertSame('https://cdn.example.test/page.png', $metadata['openGraph']['image']);
		self::assertSame('Card title', $metadata['twitter']['title']);
		self::assertSame('Site description', $metadata['twitter']['description']);
		self::assertSame('summary', $metadata['twitter']['card']);
		self::assertSame('https://cdn.example.test/page.png', $metadata['twitter']['image']);
	}

	public function testBlankPageValuesInheritDefaultsAndSocialFallbacks(): void
	{
		$defaults = $this->settings->defaults();
		$defaults['seo']['defaultDescription'] = 'Inherited description';
		$defaults['seo']['defaultSocialImage'] = '/assets/default-social.png';
		$pageSettings = array_fill_keys([
			'iikiti_content_optimization_title',
			'iikiti_content_optimization_description',
			'iikiti_content_optimization_canonical_url',
			'iikiti_content_optimization_robots_index',
			'iikiti_content_optimization_robots_follow',
			'iikiti_content_optimization_og_title',
			'iikiti_content_optimization_og_description',
			'iikiti_content_optimization_og_type',
			'iikiti_content_optimization_og_image',
			'iikiti_content_optimization_twitter_card',
			'iikiti_content_optimization_twitter_title',
			'iikiti_content_optimization_twitter_description',
			'iikiti_content_optimization_twitter_image',
		], '');
		$pageSettings['iikiti_content_optimization_canonical_url'] = '';

		$metadata = $this->resolver->resolve(
			$defaults,
			['seo' => [
				'defaultDescription' => 'Site inherited description',
				'defaultSocialImage' => '/assets/site-social.png',
			]],
			$pageSettings,
			'Article heading',
			'https://www.example.test/articles/example?source=home',
			'Example Site',
		);

		self::assertSame('Article heading | Example Site', $metadata['title']);
		self::assertSame('Site inherited description', $metadata['description']);
		self::assertSame('https://www.example.test/articles/example?source=home', $metadata['canonicalUrl']);
		self::assertSame('website', $metadata['openGraph']['type']);
		self::assertSame($metadata['title'], $metadata['openGraph']['title']);
		self::assertSame($metadata['description'], $metadata['openGraph']['description']);
		self::assertSame('https://www.example.test/assets/site-social.png', $metadata['openGraph']['image']);
		self::assertSame($metadata['canonicalUrl'], $metadata['openGraph']['url']);
		self::assertSame('summary_large_image', $metadata['twitter']['card']);
		self::assertSame($metadata['openGraph']['title'], $metadata['twitter']['title']);
		self::assertSame($metadata['openGraph']['description'], $metadata['twitter']['description']);
		self::assertSame($metadata['openGraph']['image'], $metadata['twitter']['image']);
	}

	public function testExpandsTitleTemplateTokensAndFallsBackToPlainPageTitle(): void
	{
		$defaults = $this->settings->defaults();
		$defaults['seo']['titleTemplate'] = 'Read {pageTitle} at {siteName}';

		$metadata = $this->resolver->resolve($defaults, [], [], 'SEO Guide', 'https://example.test/guide', 'Docs');

		self::assertSame('Read SEO Guide at Docs', $metadata['title']);

		$defaults['seo']['titleTemplate'] = '';
		$metadata = $this->resolver->resolve($defaults, [], [], 'SEO Guide', 'https://example.test/guide', 'Docs');

		self::assertSame('SEO Guide', $metadata['title']);
	}

	public function testResolvesRootRelativeCanonicalAndPageImagesAgainstPageOrigin(): void
	{
		$metadata = $this->resolver->resolve(
			$this->settings->defaults(),
			[],
			[
				'iikiti_content_optimization_canonical_url' => '/canonical/path',
				'iikiti_content_optimization_og_image' => '/media/og.png',
				'iikiti_content_optimization_twitter_image' => '/media/twitter.png',
			],
			'Page',
			'https://www.example.test:8443/original/path',
			'Example',
		);

		self::assertSame('https://www.example.test:8443/canonical/path', $metadata['canonicalUrl']);
		self::assertSame($metadata['canonicalUrl'], $metadata['openGraph']['url']);
		self::assertSame('https://www.example.test:8443/media/og.png', $metadata['openGraph']['image']);
		self::assertSame('https://www.example.test:8443/media/twitter.png', $metadata['twitter']['image']);
	}

	public function testPageRobotsCanSetNoindexAndNofollow(): void
	{
		$metadata = $this->resolver->resolve(
			$this->settings->defaults(),
			[],
			[
				'iikiti_content_optimization_robots_index' => 'noindex',
				'iikiti_content_optimization_robots_follow' => 'nofollow',
			],
			'Private page',
			'https://example.test/private',
			'Example',
		);

		self::assertSame(['index' => 'noindex', 'follow' => 'nofollow'], $metadata['robots']);
	}

	public function testBuildsWebSiteOrganizationAndBreadcrumbStructuredDataForValidSiteContext(): void
	{
		$metadata = $this->resolver->resolve(
			$this->settings->defaults(),
			[],
			[],
			'Optimization Guide',
			'https://www.example.test/guides/optimization',
			'Example Site',
		);
		$graph = $metadata['structuredData'][0]['@graph'];

		self::assertSame('https://schema.org', $metadata['structuredData'][0]['@context']);
		self::assertSame(['Organization', 'WebSite', 'BreadcrumbList'], array_column($graph, '@type'));
		self::assertSame('Example Site', $graph[0]['name']);
		self::assertSame('https://www.example.test/', $graph[1]['url']);
		self::assertSame('Optimization Guide', $graph[2]['itemListElement'][1]['name']);
		self::assertSame('https://www.example.test/guides/optimization', $graph[2]['itemListElement'][1]['item']);
	}

	public function testOmitsStructuredDataWhenRequiredSiteContextIsMissing(): void
	{
		$metadata = $this->resolver->resolve($this->settings->defaults(), [], [], 'Page', 'relative/path', '');

		self::assertSame([], $metadata['structuredData']);
	}

	public function testInvalidLegacyPageSeoFailsClosedWithoutBreakingPublicRendering(): void
	{
		$metadata = $this->resolver->resolve(
			$this->settings->defaults(),
			[],
			['iikiti_content_optimization_robots_index' => 'invalid-directive'],
			'Page title',
			'https://example.test/page',
			'Example Site',
		);

		self::assertSame(['index' => 'noindex', 'follow' => 'nofollow'], $metadata['robots']);
	}
}
