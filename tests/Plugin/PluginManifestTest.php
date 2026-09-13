<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\Exception\InvalidManifestException;
use iikiti\CMS\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestTest extends TestCase
{
	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function validData(array $overrides = []): array
	{
		return array_merge([
			'name' => 'Acme Blog',
			'slug' => 'acme-blog',
			'namespace' => 'Acme\\Plugin\\Blog',
			'bundle_class' => 'Acme\\Plugin\\Blog\\BlogBundle',
			'version' => '1.2.3',
			'iikiti_version' => '^1.0',
		], $overrides);
	}

	public function testParsesValidManifest(): void
	{
		$manifest = PluginManifest::fromArray($this->validData([
			'edition' => PluginManifest::EDITION_PRO,
			'dependencies' => ['acme-core' => '^2.0'],
		]));

		$this->assertSame('acme-blog', $manifest->slug);
		$this->assertSame('Acme\\Plugin\\Blog', $manifest->namespace);
		$this->assertSame('1.2.3', $manifest->version);
		$this->assertSame(PluginManifest::EDITION_PRO, $manifest->edition);
		$this->assertSame(['acme-core' => '^2.0'], $manifest->dependencies);
		$this->assertFalse($manifest->usesReservedVendor());
	}

	public function testRejectsMissingRequiredField(): void
	{
		$data = $this->validData();
		unset($data['namespace']);

		$this->expectException(InvalidManifestException::class);
		PluginManifest::fromArray($data);
	}

	public function testRejectsInvalidSlug(): void
	{
		$this->expectException(InvalidManifestException::class);
		PluginManifest::fromArray($this->validData(['slug' => 'Not Valid!']));
	}

	public function testRejectsBundleClassOutsideNamespace(): void
	{
		$this->expectException(InvalidManifestException::class);
		PluginManifest::fromArray($this->validData([
			'namespace' => 'Acme\\Plugin\\Blog',
			'bundle_class' => 'Other\\Namespace\\BlogBundle',
		]));
	}

	public function testDetectsReservedVendorNamespace(): void
	{
		$manifest = PluginManifest::fromArray($this->validData([
			'namespace' => 'iikiti\\Extension\\Blog',
			'bundle_class' => 'iikiti\\Extension\\Blog\\BlogBundle',
		]));

		$this->assertTrue($manifest->usesReservedVendor());
	}

	public function testFromFileReadsJson(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'plugin-').'.json';
		file_put_contents($path, json_encode($this->validData()));

		try {
			$manifest = PluginManifest::fromFile($path);
			$this->assertSame('acme-blog', $manifest->slug);
		} finally {
			unlink($path);
		}
	}

	public function testFromFileRejectsInvalidJson(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'plugin-').'.json';
		file_put_contents($path, '{not json');

		try {
			$this->expectException(InvalidManifestException::class);
			PluginManifest::fromFile($path);
		} finally {
			unlink($path);
		}
	}
}
