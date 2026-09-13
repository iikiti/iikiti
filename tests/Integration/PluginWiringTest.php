<?php

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Plugin\PluginValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the plugin subsystem is wired into the kernel container.
 */
final class PluginWiringTest extends KernelTestCase
{
	public function testPluginServicesAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$this->assertInstanceOf(PluginManager::class, $container->get(PluginManager::class));
		$this->assertInstanceOf(PluginDownloader::class, $container->get(PluginDownloader::class));
		$this->assertInstanceOf(PluginRegistry::class, $container->get(PluginRegistry::class));
		$this->assertInstanceOf(PluginLoader::class, $container->get(PluginLoader::class));
		$this->assertInstanceOf(PluginValidator::class, $container->get(PluginValidator::class));
	}

	public function testPluginsParameterIsArray(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$this->assertIsArray($container->getParameter('iikiti.plugins'));
		$this->assertIsArray($container->getParameter('iikiti.plugins.errors'));
	}

	public function testStoreUrlParameterResolved(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		/** @var PluginDownloader $downloader */
		$downloader = $container->get(PluginDownloader::class);

		$this->assertNotSame('', $downloader->getStoreUrl());
		$this->assertTrue($downloader->isTrustedStore(null));
	}

	public function testPluginCommandsAreRegistered(): void
	{
		self::bootKernel();
		$application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);

		$names = array_keys($application->all('iikiti:plugin'));

		$this->assertContains('iikiti:plugin:list', $names);
		$this->assertContains('iikiti:plugin:install', $names);
		$this->assertContains('iikiti:plugin:enable', $names);
		$this->assertContains('iikiti:plugin:disable', $names);
		$this->assertContains('iikiti:plugin:update', $names);
		$this->assertContains('iikiti:plugin:remove', $names);
		$this->assertContains('iikiti:plugin:verify', $names);
		$this->assertContains('iikiti:plugin:configure', $names);
		$this->assertContains('iikiti:plugin:migrate', $names);
	}
}
