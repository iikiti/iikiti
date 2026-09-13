<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\PluginAutoloader;
use iikiti\CMS\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginAutoloaderTest extends TestCase
{
	private string $tmp;

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir().'/iikiti-autoload-'.bin2hex(random_bytes(4));
		mkdir($this->tmp.'/src', 0o775, true);
	}

	protected function tearDown(): void
	{
		PluginAutoloader::reset();
		foreach (glob($this->tmp.'/src/*') ?: [] as $file) {
			unlink($file);
		}
		@rmdir($this->tmp.'/src');
		@rmdir($this->tmp);
	}

	public function testRegisterPluginMapsNamespaceToSourceDirectory(): void
	{
		$class = 'Acme\\Fixture\\Auto'.bin2hex(random_bytes(3));
		$short = substr($class, strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, strrpos($class, '\\'));

		file_put_contents($this->tmp.'/src/'.$short.'.php', "<?php\nnamespace {$namespace};\nclass {$short} {}\n");

		$manifest = PluginManifest::fromArray([
			'name' => 'Auto Fixture',
			'slug' => 'auto-fixture',
			'namespace' => 'Acme\\Plugin\\Auto',
			'bundle_class' => 'Acme\\Plugin\\Auto\\AutoBundle',
			'version' => '1.0.0',
			'iikiti_version' => '^1.0',
		]);

		// Register the fixture namespace explicitly against the temp source dir.
		PluginAutoloader::register($namespace, $this->tmp.'/src');

		$this->assertTrue(class_exists($class));
		$this->assertArrayHasKey($namespace.'\\', PluginAutoloader::getPrefixes());
	}

	public function testUnregisterRemovesPrefix(): void
	{
		PluginAutoloader::register('Acme\\Unregister\\Test', $this->tmp);
		$this->assertArrayHasKey('Acme\\Unregister\\Test\\', PluginAutoloader::getPrefixes());

		PluginAutoloader::unregister('Acme\\Unregister\\Test');
		$this->assertArrayNotHasKey('Acme\\Unregister\\Test\\', PluginAutoloader::getPrefixes());
	}

	public function testLoadReturnsFalseForUnknownClass(): void
	{
		PluginAutoloader::reset();

		$this->assertFalse(PluginAutoloader::load('Unknown\\Class\\Name'));
	}
}
