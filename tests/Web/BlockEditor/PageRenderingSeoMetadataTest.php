<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocalizedValue;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\CustomCssCompiler;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\FrontendConfigProvider;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentProviderRegistry;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use iikiti\CMS\Web\Icon\IconFontAssets;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Web\Template\TemplateResolver;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsDescriptorResolver;
use iikiti\Extension\ContentOptimization\Analytics\ClarityAnalyticsConnector;
use iikiti\Extension\ContentOptimization\Analytics\GoogleAnalytics4Connector;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/BuiltInAnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorRegistry.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/GoogleAnalytics4Connector.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/ClarityAnalyticsConnector.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsDescriptorResolver.php';

final class PageRenderingSeoMetadataTest extends TestCase
{
	public function testPublicRenderPlacesSeoMetadataInTheDocumentHead(): void
	{
		$site = $this->site();
		$template = new Template();
		$template->setLayout('seo-test-page.twig');
		$template->setProperty('title', 'Fallback title');
		$template->setRegions([]);
		$page = new Page();
		$page->setProperty('template_id', 17);
		$page->setProperty('title', 'Rendered article title');
		$page->setProperty('settings', [
			'iikiti_content_optimization_title' => 'Published SEO title',
			'iikiti_content_optimization_description' => 'Published description',
		]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('find')->willReturn($template);
		$twig = $this->twig(new ChainLoader([
			new ArrayLoader(['seo-test-page.twig' => '<main>Rendered page body</main>']),
			new FilesystemLoader(dirname(__DIR__, 3).'/templates'),
		]));
		$siteRegistry = new SiteRegistry(
			new RequestStack(),
			$this->createStub(ManagerRegistry::class),
			new GuestApiTokenManager('test-secret'),
			'consumer',
		);
		SiteRegistry::pushSite($site);

		try {
			$response = $this->directRenderer($entityManager, $twig)->render(
				Request::create('https://internal-api.example.test/articles/intro?campaign=tracking'),
				$page,
			);
		} finally {
			SiteRegistry::popSite();
		}

		$html = (string) $response->getContent();
		self::assertStringContainsString('<title>Published SEO title</title>', $html);
		self::assertStringContainsString('href="https://canonical.example.test/articles/intro"', $html);
		self::assertStringContainsString('<main>Rendered page body</main>', $html);
		self::assertStringNotContainsString('campaign=tracking', $html);
	}

	public function testLocalizedPageAndSiteNamesUseTheRenderLocaleForTitleAndOpenGraph(): void
	{
		$site = $this->site();
		$site->setProperty('name', LocalizedValue::fromTranslations(['en' => 'Example Site', 'fr-CA' => 'Site Exemple']));
		$page = new Page();
		$page->setProperty('title', LocalizedValue::fromTranslations(['en' => 'About', 'fr-CA' => 'À propos']));
		$template = new Template();
		$template->setProperty('title', 'Fallback title');
		$request = Request::create('https://canonical.example.test/about');
		$request->setLocale('fr-CA');
		$html = $this->renderHead($this->rendering(), $request, $this->state($site, $page, $template, []));

		self::assertStringContainsString('<html lang="fr-CA"', $html);
		self::assertStringContainsString('<title>À propos | Site Exemple</title>', $html);
		self::assertStringContainsString('<meta property="og:locale" content="fr_CA">', $html);
		self::assertStringContainsString('Site Exemple', $html);

		$zhRequest = Request::create('https://canonical.example.test/about');
		$zhRequest->setLocale('zh-Hans-CN');
		$zhHtml = $this->renderHead($this->rendering(), $zhRequest, $this->state($site, $page, $template, []));
		self::assertStringContainsString('<meta property="og:locale" content="zh_CN">', $zhHtml);
	}

	public function testDirectRenderHeadUsesPublishedPageSeoAndStripsQueryFromCanonicalUrl(): void
	{
		$rendering = $this->rendering();
		$site = $this->site();
		$page = new Page();
		$page->setProperty('title', 'Rendered article title');
		$template = new Template();
		$template->setProperty('title', 'Fallback template title');
		$publishedSettings = [
			'iikiti_content_optimization_title' => 'Published SEO title',
			'iikiti_content_optimization_description' => 'Published description',
		];
		$request = Request::create('https://internal-api.example.test/articles/intro?campaign=tracking');

		$html = $this->renderHead($rendering, $request, $this->state($site, $page, $template, $publishedSettings));

		self::assertStringContainsString('<title>Published SEO title</title>', $html);
		self::assertStringContainsString('content="Published&#x20;description"', $html);
		self::assertStringContainsString('href="https&#x3A;&#x2F;&#x2F;canonical.example.test&#x2F;articles&#x2F;intro"', $html);
		self::assertStringNotContainsString('campaign=tracking', $html);
		self::assertStringContainsString('name="twitter:card"', $html);
		self::assertStringContainsString('property="og:title"', $html);
	}

	public function testEditorPreviewIgnoresDraftSeoValuesAndForcesNoindexNofollow(): void
	{
		$rendering = $this->rendering();
		$site = $this->site();
		$page = new Page();
		$page->setProperty('title', 'Preview heading');
		$template = new Template();
		$template->setProperty('title', 'Fallback template title');
		$draftSettings = [
			'iikiti_content_optimization_title' => 'Draft-only secret',
			'iikiti_content_optimization_description' => 'Draft description',
		];
		$request = Request::create('https://canonical.example.test/articles/preview');

		$html = $this->renderHead($rendering, $request, $this->state($site, $page, $template, $draftSettings, true));

		self::assertStringContainsString('<title>Preview heading | Site Name</title>', $html);
		self::assertStringContainsString('name="robots" content="noindex,nofollow"', $html);
		self::assertStringNotContainsString('Draft-only secret', $html);
		self::assertStringNotContainsString('Draft description', $html);
		self::assertStringNotContainsString('googletagmanager', $html);
		self::assertStringNotContainsString('clarity.ms', $html);
	}

	public function testHomepageUsesRenderedTitleAndDoesNotUseTemplateSettingsAsSeoOverrides(): void
	{
		$rendering = $this->rendering();
		$site = $this->site();
		$template = new Template();
		$template->setProperty('title', 'Rendered home title');
		$template->setSettings(['iikiti_content_optimization_title' => 'Shared template override']);

		$html = $this->renderHead(
			$rendering,
			Request::create('https://canonical.example.test/?tracking=1'),
			$this->state($site, null, $template, []),
		);

		self::assertStringContainsString('<title>Rendered home title | Site Name</title>', $html);
		self::assertStringContainsString('href="https&#x3A;&#x2F;&#x2F;canonical.example.test&#x2F;"', $html);
		self::assertStringNotContainsString('Shared template override', $html);
		self::assertStringNotContainsString('tracking=1', $html);
	}

	public function testDisabledSitePluginDoesNotEmitOptimizationMetadata(): void
	{
		$rendering = $this->rendering();
		$site = $this->site();
		$configuration = $site->getConfiguration();
		$configuration->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		$site->setConfiguration($configuration);
		$page = new Page();
		$page->setProperty('title', 'Page title');
		$template = new Template();
		$template->setProperty('title', 'Legacy template title');
		$template->setSettings(['iikiti_content_optimization_title' => 'Must not be used']);

		$html = $this->renderHead(
			$rendering,
			Request::create('https://canonical.example.test/articles/disabled'),
			$this->state($site, $page, $template, [
				'iikiti_content_optimization_title' => 'Page SEO title',
			]),
		);

		self::assertStringContainsString('<title>Legacy template title</title>', $html);
		self::assertStringNotContainsString('name="description"', $html);
		self::assertStringNotContainsString('rel="canonical"', $html);
		self::assertStringNotContainsString('application/ld+json', $html);
		self::assertStringNotContainsString('Page SEO title', $html);
	}

	public function testEditorModeRemainsNoindexWhenSitePluginIsDisabled(): void
	{
		$site = $this->site();
		$configuration = $site->getConfiguration();
		$configuration->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		$site->setConfiguration($configuration);
		$template = new Template();
		$template->setProperty('title', 'Editor preview');
		$html = $this->renderHead(
			$this->rendering(),
			Request::create('https://canonical.example.test/preview?edit'),
			$this->state($site, null, $template, [], editorMode: true),
		);

		self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $html);
		self::assertStringNotContainsString('rel="canonical"', $html);
		self::assertStringNotContainsString('iikiti-analytics-config', $html);
	}

	public function testHomepageEditorSettingsUseTheSiteTargetAndFilterUnrelatedValues(): void
	{
		$site = $this->site();
		$this->setObjectId($site, 42);
		$site->setProperty('settings_draft', [
			'iikiti_content_optimization_title' => 'Homepage draft title',
			'name' => 'Must not be included',
		]);
		$template = new Template();
		$context = $this->homepageSettingsContext($site, $template, true);

		self::assertSame('site_home', $context['type']);
		self::assertSame(42, $context['id']);
		self::assertSame(['iikiti_content_optimization_title' => 'Homepage draft title'], $context['values']);
		self::assertSame(['site_home', 'template'], array_column($context['options'], 'type'));

		$templateOnlyContext = $this->homepageSettingsContext($site, $template, false);
		self::assertSame('template', $templateOnlyContext['type']);
		self::assertSame([], $templateOnlyContext['options']);

		$configuration = $site->getConfiguration();
		$configuration->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		$site->setConfiguration($configuration);
		$disabledContext = $this->homepageSettingsContext($site, $template, true);
		self::assertSame('template', $disabledContext['type']);
	}

	public function testActiveConsentBannerFragmentIsPlacedInsideTheBody(): void
	{
		$rendering = $this->rendering();
		$site = $this->site();
		$configuration = $site->getConfiguration();
		$configuration->enablePlugin('consent-management');
		$site->setConfiguration($configuration);
		$this->setProperty(
			$rendering,
			'fragmentRuntime',
			new FragmentRuntime(new FragmentProviderRegistry([new ConsentBannerTestFragment()])),
		);
		$template = new Template();
		$template->setProperty('title', 'Rendered title');
		$html = $this->renderHead($rendering, Request::create('https://canonical.example.test/'), $this->state($site, null, $template, []));
		$headEnd = strpos($html, '</head>');
		$banner = strpos($html, '<div id="consent-banner-test"');

		self::assertNotFalse($headEnd);
		self::assertNotFalse($banner);
		self::assertGreaterThan($headEnd, $banner);
	}

	public function testAnalyticsConfigurationIsInertAndRequiresActiveConsentManagement(): void
	{
		$site = $this->site();
		$configuration = $site->getConfiguration();
		$configuration->enablePlugin('consent-management');
		$configuration->setPluginConfiguration(ContentOptimizationSettings::PLUGIN_SLUG, [
			'version' => 1,
			'overrides' => [
				'analytics' => [
					'ga4' => ['enabled' => true, 'measurementId' => 'G-123ABC'],
				],
			],
		]);
		$site->setConfiguration($configuration);
		$rendering = $this->renderingWithAnalyticsConnectors();
		$template = new Template();
		$template->setProperty('title', 'Analytics page');

		// Without an active consent banner provider in FragmentRuntime, analytics fail closed
		$noBannerHtml = $this->renderHead($rendering, Request::create('https://canonical.example.test/analytics'), $this->state($site, null, $template, []));
		self::assertStringNotContainsString('iikiti-analytics-config', $noBannerHtml);

		// With an active consent banner provider in FragmentRuntime, inert analytics config is emitted
		$this->setProperty(
			$rendering,
			'fragmentRuntime',
			new FragmentRuntime(new FragmentProviderRegistry([new ConsentBannerTestFragment()])),
		);
		$html = $this->renderHead($rendering, Request::create('https://canonical.example.test/analytics'), $this->state($site, null, $template, []));

		self::assertStringContainsString('id="iikiti-analytics-config" type="application/json"', $html);
		self::assertStringContainsString('www.googletagmanager.com', $html);
		self::assertStringContainsString('G-123ABC', $html);
		self::assertStringNotContainsString('<script src="https://www.googletagmanager.com', $html);
		self::assertStringNotContainsString('rel="preconnect"', $html);

		$withoutConsent = $this->site();
		$config = $withoutConsent->getConfiguration();
		$config->setPluginConfiguration(ContentOptimizationSettings::PLUGIN_SLUG, [
			'version' => 1,
			'overrides' => [
				'analytics' => [
					'ga4' => ['enabled' => true, 'measurementId' => 'G-123ABC'],
				],
			],
		]);
		$withoutConsent->setConfiguration($config);
		$withoutConsentHtml = $this->renderHead(
			$rendering,
			Request::create('https://canonical.example.test/analytics'),
			$this->state($withoutConsent, null, $template, []),
		);
		self::assertStringNotContainsString('iikiti-analytics-config', $withoutConsentHtml);
	}

	public function testHeadEscapesTextAndAttributesAndHexEscapesJsonLd(): void
	{
		$rendering = $this->rendering();
		$site = $this->site('<Site & Co>');
		$page = new Page();
		$page->setProperty('title', '</script><script>alert("page")</script>');
		$template = new Template();
		$template->setProperty('title', 'Fallback title');
		$publishedSettings = [
			'iikiti_content_optimization_title' => '<script>alert("title")</script>',
			'iikiti_content_optimization_description' => 'A "quoted" & unsafe description',
		];

		$html = $this->renderHead(
			$rendering,
			Request::create('https://canonical.example.test/articles/unsafe'),
			$this->state($site, $page, $template, $publishedSettings),
		);

		self::assertStringContainsString('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', $html);
		self::assertStringContainsString('A&#x20;&quot;quoted&quot;&#x20;&amp;&#x20;unsafe&#x20;description', $html);
		self::assertStringNotContainsString('</script><script>alert("page")</script>', $html);
		self::assertStringContainsString('\\u003C\\/script\\u003E\\u003Cscript\\u003E', $html);
		self::assertSame(1, substr_count($html, '<link rel="canonical"'));
	}

	private function rendering(): PageRendering
	{
		$reflection = new \ReflectionClass(PageRendering::class);
		$rendering = $reflection->newInstanceWithoutConstructor();
		$settings = new ContentOptimizationSettings();
		$this->setProperty($rendering, 'applications', $this->createStub(ApplicationRegistry::class));
		$this->setProperty($rendering, 'contentOptimizationSettings', $settings);
		$this->setProperty($rendering, 'seoMetadataResolver', new SeoMetadataResolver($settings, new PageSeoSettingsNormalizer()));
		$this->setProperty($rendering, 'localizedPropertyResolver', new LocalizedPropertyResolver(
			new LocaleResolver(),
			new ObjectPropertyDefinitionRegistry([]),
			$this->createStub(ApplicationRegistry::class),
			$this->createStub(Security::class),
		));
		$this->setProperty($rendering, 'twig', $this->twig());

		return $rendering;
	}

	private function renderingWithAnalyticsConnectors(): PageRendering
	{
		$registry = new AnalyticsConnectorRegistry([new GoogleAnalytics4Connector(), new ClarityAnalyticsConnector()]);
		$settings = new ContentOptimizationSettings($registry);
		$rendering = (new \ReflectionClass(PageRendering::class))->newInstanceWithoutConstructor();
		$this->setProperty($rendering, 'applications', $this->createStub(ApplicationRegistry::class));
		$this->setProperty($rendering, 'twig', $this->twig());
		$this->setProperty($rendering, 'contentOptimizationSettings', $settings);
		$this->setProperty($rendering, 'seoMetadataResolver', new SeoMetadataResolver($settings, new PageSeoSettingsNormalizer()));
		$this->setProperty($rendering, 'analyticsDescriptorResolver', new AnalyticsDescriptorResolver($registry));

		return $rendering;
	}

	private function twig(?ChainLoader $loader = null): Environment
	{
		$twig = new Environment($loader ?? new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
		$twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
		$twig->addFunction(new TwigFunction('encore_entry_link_tags', static fn (string $entry): string => 'entry:'.$entry));
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => ''));
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);

		return $twig;
	}

	private function directRenderer(EntityManagerInterface $entityManager, Environment $twig): PageRendering
	{
		$empty = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$cssCompiler = new CustomCssCompiler();
		$registry = $empty(BlockTypeRegistry::class);
		$blockRenderer = new BlockRenderer(
			$registry,
			$twig,
			$empty(QueryExecutor::class),
			$empty(QueryFieldCatalog::class),
			$cssCompiler,
		);
		$templateRenderer = new TemplateRenderer($blockRenderer, $twig);
		$security = $this->createStub(Security::class);
		$configProvider = new FrontendConfigProvider(
			$security,
			$this->createStub(PermissionChecker::class),
			$this->createStub(ApiTokenManager::class),
			$empty(PluginRegistry::class),
		);
		$settings = new ContentOptimizationSettings();

		return new PageRendering(
			new IconFontAssets(),
			new TemplateResolver($entityManager),
			$templateRenderer,
			$configProvider,
			new EditorSettingsManager($entityManager),
			$cssCompiler,
			$security,
			$twig,
			new InlineAssetMinificationOptionsResolver($this->createStub(EventDispatcherInterface::class), true, true, false),
			$this->createStub(ApplicationRegistry::class),
			contentOptimizationSettings: $settings,
			seoMetadataResolver: new SeoMetadataResolver($settings, new PageSeoSettingsNormalizer()),
		);
	}

	private function site(string $name = 'Site Name'): Site
	{
		$site = new Site();
		$site->setProperty('name', $name);
		$site->setProperty(Site::DOMAIN_PROPERTY_KEY, 'canonical.example.test');

		return $site;
	}

	/** @param array<string,mixed> $pageSettings
	 * @return array<string,mixed>
	 */
	private function state(Site $site, ?Page $page, Template $template, array $pageSettings, bool $editorMode = false): array
	{
		return [
			'template' => $template,
			'site' => $site,
			'object' => $page,
			'editor_mode' => $editorMode,
			'can_edit' => $editorMode,
			'config' => null,
			'published_page_settings' => $pageSettings,
			'scope_classes' => [],
		];
	}

	/** @param array<string,mixed> $state */
	private function renderHead(PageRendering $rendering, Request $request, array $state): string
	{
		$method = new \ReflectionMethod(PageRendering::class, 'baseLayout');

		return $method->invoke($rendering, $request, $state);
	}

	/** @return array<string,mixed> */
	private function homepageSettingsContext(Site $site, Template $template, bool $canPublishTemplate): array
	{
		$permissionChecker = $this->createStub(PermissionChecker::class);
		$permissionChecker->method('canAccess')->willReturnCallback(
			static fn (User $user, string $objectType, string $action, ?\iikiti\CMS\Entity\DbObject $object = null): bool => !('Template' === $objectType && 'publish' === $action) || $canPublishTemplate,
		);
		$roleHierarchy = $this->createStub(RoleHierarchyInterface::class);
		$roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_SYSTEM']);
		$siteAccess = new DigitalAssetAccessPolicy($this->createStub(SiteRepository::class), $roleHierarchy);
		$workflowAccess = new WorkflowAccessPolicy($siteAccess, $roleHierarchy);
		$configProvider = new FrontendConfigProvider(
			$this->createStub(Security::class),
			$permissionChecker,
			$this->createStub(ApiTokenManager::class),
			$this->createStub(PluginRegistry::class),
		);
		$rendering = (new \ReflectionClass(PageRendering::class))->newInstanceWithoutConstructor();
		$this->setProperty($rendering, 'applications', $this->createStub(ApplicationRegistry::class));
		$this->setProperty($rendering, 'configProvider', $configProvider);
		$this->setProperty($rendering, 'contentOptimizationSettings', new ContentOptimizationSettings());
		$this->setProperty($rendering, 'settingsManager', new EditorSettingsManager($this->createStub(EntityManagerInterface::class)));
		$this->setProperty($rendering, 'workflowAccessPolicy', $workflowAccess);
		$method = new \ReflectionMethod(PageRendering::class, 'settingsContext');

		return $method->invoke($rendering, null, $template, new User(), true, $site, Request::create('https://canonical.example.test/'));
	}

	private function setObjectId(object $object, int $id): void
	{
		(new \ReflectionClass(\iikiti\CMS\Entity\DbObject::class))->getProperty('id')->setValue($object, $id);
	}

	private function setProperty(object $object, string $name, mixed $value): void
	{
		$property = new \ReflectionProperty($object, $name);
		$property->setValue($object, $value);
	}
}

final class ConsentBannerTestFragment implements FragmentProviderInterface
{
	public function getName(): string
	{
		return 'consent-management.banner';
	}

	public function render(FragmentContext $context): FragmentResult
	{
		return new FragmentResult('<div id="consent-banner-test" hidden></div>');
	}
}
