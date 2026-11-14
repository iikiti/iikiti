<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Filters;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsEvent;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Service\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

final class InlineAssetMinificationOptionsResolverTest extends TestCase
{
	public function testSiteOptionsOverrideEnvironmentDefaults(): void
	{
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, false, false);
		$site = $this->siteWithConfiguration([
			'inline_css_minify' => false,
			'inline_js_minify' => true,
			'inline_js_uglify' => true,
		]);

		$options = $resolver->resolve(new Request(), $site);

		self::assertFalse($options->cssMinify);
		self::assertTrue($options->jsMinify);
		self::assertTrue($options->jsUglify);
	}

	public function testPluginEventCanChangeResolvedOptionsAndRunsOnlyOncePerRequest(): void
	{
		$dispatcher = new EventDispatcher();
		$dispatches = 0;
		$dispatcher->addListener(InlineAssetMinificationOptionsEvent::NAME, static function (InlineAssetMinificationOptionsEvent $event) use (&$dispatches): void {
			++$dispatches;
			$event->setMinifyCss(false);
			$event->setUglifyJavaScript(true);
		});
		$resolver = new InlineAssetMinificationOptionsResolver($dispatcher, true, true, false);
		$request = new Request();

		$options = $resolver->resolveForRequest($request);
		$cachedOptions = $resolver->resolveForRequest($request);

		self::assertFalse($options->cssMinify);
		self::assertTrue($options->jsMinify);
		self::assertTrue($options->jsUglify);
		self::assertSame($options, $cachedOptions);
		self::assertSame(1, $dispatches);
	}

	public function testInvalidSiteValuesFallBackToEnvironmentDefaults(): void
	{
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, false, true);
		$site = $this->siteWithConfiguration([
			'inline_css_minify' => 'false',
			'inline_js_minify' => 0,
			'inline_js_uglify' => null,
		]);

		$options = $resolver->resolve(new Request(), $site);

		self::assertTrue($options->cssMinify);
		self::assertFalse($options->jsMinify);
		self::assertFalse($options->jsUglify);
	}

	/** @param array<string,mixed> $values */
	private function siteWithConfiguration(array $values): Site
	{
		$site = $this->createStub(Site::class);
		$site->method('getConfiguration')->willReturn(new Configuration($values));

		return $site;
	}
}
