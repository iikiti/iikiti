<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Registry;

use iikiti\CMS\Registry\AbstractRouteContextContributor;
use iikiti\CMS\Registry\RouteContextClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RouteContextClassifierTest extends TestCase
{
	public function testAggregatesPluginContributionsAndCoreDefaults(): void
	{
		$contributor = new class () extends AbstractRouteContextContributor {
			public function getConsumerProxyRoutes(): array
			{
				return ['plugin_public_doc'];
			}

			public function getSiteIndependentRoutes(): array
			{
				return ['plugin_global_callback'];
			}

			public function getApplicationAdminSettingsPathPatterns(): array
			{
				return ['^/api/admin/applications/[^/]+/custom-plugin$'];
			}

			public function getSiteScopedSettingsPathPatterns(): array
			{
				return ['^/api/site/[^/]+/custom-plugin$'];
			}

			public function getGuestTokenAllowedPathPrefixes(): array
			{
				return ['/api/public/custom-plugin/'];
			}

			public function getMcpExcludedPathPrefixes(): array
			{
				return ['/api/public/custom-plugin/'];
			}

			public function getSessionFormPathPrefixes(): array
			{
				return ['/api/plugin-forms/'];
			}
		};

		$classifier = new RouteContextClassifier([$contributor]);

		self::assertTrue($classifier->matchesConsumerProxyRoute('home'));
		self::assertTrue($classifier->matchesConsumerProxyRoute('plugin_public_doc'));
		self::assertTrue($classifier->matchesSiteIndependentRoute('plugin_global_callback', '/plugin/cb', 'all', false));
		self::assertTrue($classifier->matchesApplicationAdminSettingsPath('/api/admin/applications/1/custom-plugin'));
		self::assertTrue($classifier->matchesSiteScopedSettingsPath('/api/site/1/custom-plugin'));
		self::assertTrue($classifier->isGuestTokenAllowedPath('/api/public/custom-plugin/feed'));
		self::assertTrue($classifier->isMcpExcludedPath('/api/public/custom-plugin/feed', 'plugin_feed'));
		self::assertTrue($classifier->isMcpExcludedPath('/api/internal/run', 'any_route'));
		self::assertTrue($classifier->isSessionFormPath('/api/plugin-forms/contact'));
		self::assertTrue($classifier->matchesSessionForwardedHostRoute('other', '/api/plugin-forms/contact'));
		self::assertContains('/api/plugin-forms/', $classifier->getConsumerUrlRewritePrefixes());
	}

	public function testSiteAccessExemptionsIncludeContributedSettingsPaths(): void
	{
		$contributor = new class () extends AbstractRouteContextContributor {
			public function getSiteScopedSettingsPathPatterns(): array
			{
				return ['^/api/site/[^/]+/plugin-settings$'];
			}
		};

		$classifier = new RouteContextClassifier([$contributor]);
		$request = Request::create('/api/site/5/plugin-settings');
		$request->attributes->set('_route', 'plugin_site_settings');

		self::assertTrue($classifier->matchesSiteAccessCheckExempt($request, 'all'));
	}

	public function testStaticBackwardsCompatibilityHelpersStillWork(): void
	{
		self::assertTrue(RouteContextClassifier::isConsumerProxyRoute('home'));
		self::assertTrue(RouteContextClassifier::isInternalWorkerRoute('api_internal_batch_item_process'));
		self::assertTrue(RouteContextClassifier::isSiteIndependentRoute('admin_spa', '/admin', 'all', false));
		self::assertTrue(RouteContextClassifier::isPublicRenderOrAssetRoute('api_public_render_page'));
	}
}
