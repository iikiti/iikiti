<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\Configuration;
use PHPUnit\Framework\TestCase;

final class PluginActivationCheckerTest extends TestCase
{
	public function testEvaluatesRequiredDefaultOnAndOptInModesPerSite(): void
	{
		$registry = new PluginRegistry();
		$registry->add(new PluginManifest('Req', 'req-plugin', 'Acme\\Req', 'Acme\\Req\\ReqBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_REQUIRED));
		$registry->add(new PluginManifest('Def', 'def-plugin', 'Acme\\Def', 'Acme\\Def\\DefBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_DEFAULT_ON));
		$registry->add(new PluginManifest('Opt', 'opt-plugin', 'Acme\\Opt', 'Acme\\Opt\\OptBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_OPT_IN));

		$checker = new PluginActivationChecker($registry, $this->createStub(SiteRegistry::class));

		$freshSite = new Site();
		self::assertTrue($checker->isActiveForSite('req-plugin', $freshSite));
		self::assertTrue($checker->isActiveForSite('def-plugin', $freshSite));
		self::assertFalse($checker->isActiveForSite('opt-plugin', $freshSite));
		self::assertFalse($checker->isActiveForSite('unloaded-plugin', $freshSite));

		$configuredSite = new Site();
		$config = new Configuration([]);
		$config->disablePlugin('def-plugin', true);
		$config->enablePlugin('opt-plugin', false);
		$configuredSite->setConfiguration($config);

		self::assertTrue($checker->isActiveForSite('req-plugin', $configuredSite));
		self::assertFalse($checker->isActiveForSite('def-plugin', $configuredSite));
		self::assertTrue($checker->isActiveForSite('opt-plugin', $configuredSite));
	}

	public function testEvaluatesApplicationScope(): void
	{
		$registry = new PluginRegistry();
		$registry->add(new PluginManifest('AppOpt', 'app-opt', 'Acme\\AppOpt', 'Acme\\AppOpt\\AppOptBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_OPT_IN, scope: PluginManifest::SCOPE_APPLICATION));

		$checker = new PluginActivationChecker($registry, $this->createStub(SiteRegistry::class), $this->createStub(ApplicationRegistry::class));

		$app = new Application();
		self::assertFalse($checker->isActiveForApplication('app-opt', $app));

		$config = new Configuration([]);
		$config->enablePlugin('app-opt');
		$app->setConfiguration($config);
		self::assertTrue($checker->isActiveForApplication('app-opt', $app));
	}
}
