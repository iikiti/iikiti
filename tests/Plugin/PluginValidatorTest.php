<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\Exception\InvalidManifestException;
use iikiti\CMS\Plugin\Exception\NamespaceViolationException;
use iikiti\CMS\Plugin\Exception\PluginStateException;
use iikiti\CMS\Plugin\Exception\SecurityViolationException;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginState;
use iikiti\CMS\Plugin\PluginValidator;
use PHPUnit\Framework\TestCase;

final class PluginValidatorTest extends TestCase
{
	private string $tmp;

	private PluginValidator $validator;

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir().'/iikiti-plugin-test-'.bin2hex(random_bytes(4));
		mkdir($this->tmp.'/installed/plugin', 0o775, true);
		mkdir($this->tmp.'/active', 0o775, true);
		mkdir($this->tmp.'/outside', 0o775, true);
		$this->validator = new PluginValidator();
	}

	protected function tearDown(): void
	{
		$this->remove($this->tmp);
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function manifest(array $overrides = []): PluginManifest
	{
		return PluginManifest::fromArray(array_merge([
			'name' => 'Acme Blog',
			'slug' => 'acme-blog',
			'namespace' => 'Acme\\Plugin\\Blog',
			'bundle_class' => 'Acme\\Plugin\\Blog\\BlogBundle',
			'version' => '1.0.0',
			'iikiti_version' => '^1.0',
		], $overrides));
	}

	public function testRejectsReservedNamespaceForThirdPartyPlugins(): void
	{
		$manifest = $this->manifest([
			'namespace' => 'iikiti\\Extension\\Blog',
			'bundle_class' => 'iikiti\\Extension\\Blog\\BlogBundle',
		]);

		$this->expectException(NamespaceViolationException::class);
		$this->validator->validateManifest($manifest, false);
	}

	public function testAllowsReservedNamespaceWhenTrusted(): void
	{
		$manifest = $this->manifest([
			'namespace' => 'iikiti\\Extension\\Blog',
			'bundle_class' => 'iikiti\\Extension\\Blog\\BlogBundle',
		]);

		$this->validator->validateManifest($manifest, true);
		$this->addToAssertionCount(1);
	}

	public function testRejectsUnknownEdition(): void
	{
		$this->expectException(InvalidManifestException::class);
		$this->validator->validateManifest($this->manifest(['edition' => 'enterprise']), false);
	}

	public function testResolvesSafeSymlink(): void
	{
		symlink('../installed/plugin', $this->tmp.'/active/plugin');

		$target = $this->validator->resolveSymlinkTarget($this->tmp.'/active/plugin', $this->tmp.'/installed');

		$this->assertSame(realpath($this->tmp.'/installed/plugin'), $target);
	}

	public function testRejectsSymlinkEscapingInstalledDirectory(): void
	{
		symlink($this->tmp.'/outside', $this->tmp.'/active/escape');

		$this->expectException(SecurityViolationException::class);
		$this->validator->resolveSymlinkTarget($this->tmp.'/active/escape', $this->tmp.'/installed');
	}

	public function testEnforceStateAllowsPublished(): void
	{
		$this->validator->enforceState(PluginState::Published, false, 'prod');
		$this->addToAssertionCount(1);
	}

	public function testEnforceStateRejectsNonApprovedWithoutOverride(): void
	{
		$this->expectException(PluginStateException::class);
		$this->validator->enforceState(PluginState::Testing, false, 'dev');
	}

	public function testEnforceStateAllowsNonApprovedInDevelopmentWithOverride(): void
	{
		$this->validator->enforceState(PluginState::Testing, true, 'dev');
		$this->addToAssertionCount(1);
	}

	public function testEnforceStateRejectsNonApprovedInProductionEvenWithOverride(): void
	{
		$this->expectException(PluginStateException::class);
		$this->validator->enforceState(PluginState::Development, true, 'prod');
	}

	public function testEnforceStateAlwaysRejectsRejectedPlugins(): void
	{
		$this->expectException(PluginStateException::class);
		$this->validator->enforceState(PluginState::Rejected, true, 'dev');
	}

	public function testValidateDependenciesSatisfied(): void
	{
		$manifest = $this->manifest(['dependencies' => ['acme-core' => '^2.0']]);

		$this->validator->validateDependencies($manifest, ['acme-core' => '2.3.1']);
		$this->addToAssertionCount(1);
	}

	public function testValidateDependenciesMissing(): void
	{
		$manifest = $this->manifest(['dependencies' => ['acme-core' => '^2.0']]);

		$this->expectException(InvalidManifestException::class);
		$this->validator->validateDependencies($manifest, []);
	}

	public function testValidateDependenciesVersionConflict(): void
	{
		$manifest = $this->manifest(['dependencies' => ['acme-core' => '^2.0']]);

		$this->expectException(InvalidManifestException::class);
		$this->validator->validateDependencies($manifest, ['acme-core' => '1.5.0']);
	}
}
