<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Plugin\Exception\PluginStateException;
use iikiti\CMS\Plugin\Exception\SecurityViolationException;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginPackage;
use iikiti\CMS\Plugin\PluginSource;
use iikiti\CMS\Plugin\PluginState;
use iikiti\CMS\Plugin\PluginValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginDownloaderTest extends TestCase
{
	private string $projectDir;

	private string $fileContents = 'PLUGIN-ZIP-BYTES';

	private string $publicKey = '';

	private string $signature = '';

	protected function setUp(): void
	{
		$this->projectDir = sys_get_temp_dir().'/iikiti-downloader-'.bin2hex(random_bytes(4));
		mkdir($this->projectDir, 0o775, true);

		if (function_exists('sodium_crypto_sign_keypair')) {
			$keypair = sodium_crypto_sign_keypair();
			$this->publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
			$this->signature = base64_encode(sodium_crypto_sign_detached($this->fileContents, sodium_crypto_sign_secretkey($keypair)));
		}
	}

	protected function tearDown(): void
	{
		$this->remove($this->projectDir);
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
	 * @param array<string,mixed> $metadataOverrides
	 */
	private function downloader(
		array $metadataOverrides = [],
		string $environment = 'dev',
		bool $allowNonApproved = false,
		bool $allowThirdParty = false,
		bool $requireSignature = true,
		?string $publicKey = null,
		?string $fileContents = null,
	): PluginDownloader {
		$fileContents ??= $this->fileContents;
		$metadata = array_merge([
			'download_url' => 'https://store.iikiti.com/downloads/plugin.zip',
			'checksum' => 'sha256:'.hash('sha256', $fileContents),
			'signature' => '' !== $this->signature ? $this->signature : null,
			'signed_by' => 'iikiti',
			'state' => 'published',
		], $metadataOverrides);

		$client = new MockHttpClient(function (string $method, string $url) use ($metadata, $fileContents): MockResponse {
			if (str_ends_with($url, '.zip')) {
				return new MockResponse($fileContents, ['http_code' => 200]);
			}

			return new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR), [
				'http_code' => 200,
				'response_headers' => ['content-type' => 'application/json'],
			]);
		});

		return new PluginDownloader(
			httpClient: $client,
			validator: new PluginValidator(),
			projectDir: $this->projectDir,
			environment: $environment,
			storeUrl: 'https://store.iikiti.com',
			allowNonApproved: $allowNonApproved,
			allowThirdParty: $allowThirdParty,
			requireSignature: $requireSignature,
			storePublicKey: $publicKey ?? $this->publicKey,
			timeout: 5,
		);
	}

	public function testDownloadsAndVerifiesPackage(): void
	{
		$package = $this->downloader()->download('acme-blog', '1.0.0');

		$this->assertInstanceOf(PluginPackage::class, $package);
		$this->assertSame('acme-blog', $package->slug);
		$this->assertSame(PluginState::Published, $package->state);
		$this->assertSame(PluginSource::IikitiStore, $package->source);
		$this->assertFileExists($package->filePath);
	}

	public function testRejectsChecksumMismatch(): void
	{
		$downloader = $this->downloader(['checksum' => 'sha256:deadbeef']);

		$this->expectException(SecurityViolationException::class);
		$downloader->download('acme-blog', '1.0.0');
	}

	public function testRejectsRejectedState(): void
	{
		$downloader = $this->downloader(['state' => 'rejected'], allowNonApproved: true);

		$this->expectException(PluginStateException::class);
		$downloader->download('acme-blog', '1.0.0');
	}

	public function testRejectsNonApprovedWithoutOverride(): void
	{
		$downloader = $this->downloader(['state' => 'testing'], allowNonApproved: false);

		$this->expectException(PluginStateException::class);
		$downloader->download('acme-blog', '1.0.0');
	}

	public function testAllowsNonApprovedInDevelopmentWithOverride(): void
	{
		$downloader = $this->downloader(['state' => 'testing'], environment: 'dev', allowNonApproved: true);

		$package = $downloader->download('acme-blog', '1.0.0');

		$this->assertSame(PluginState::Testing, $package->state);
	}

	public function testRejectsNonApprovedInProductionEvenWithOverride(): void
	{
		$downloader = $this->downloader(['state' => 'testing'], environment: 'prod', allowNonApproved: true);

		$this->expectException(PluginStateException::class);
		$downloader->download('acme-blog', '1.0.0');
	}

	public function testRejectsThirdPartyStoreInProduction(): void
	{
		$downloader = $this->downloader(environment: 'prod', allowThirdParty: true);

		$this->expectException(SecurityViolationException::class);
		$downloader->download('acme-blog', '1.0.0', 'https://plugins.example.com');
	}

	public function testRejectsThirdPartyStoreWhenDisabled(): void
	{
		$downloader = $this->downloader(environment: 'dev', allowThirdParty: false);

		$this->expectException(SecurityViolationException::class);
		$downloader->download('acme-blog', '1.0.0', 'https://plugins.example.com');
	}

	public function testResolvesThirdPartySourceInDevelopment(): void
	{
		$downloader = $this->downloader(environment: 'dev', allowThirdParty: true);

		$this->assertSame(PluginSource::ThirdParty, $downloader->resolveSource('https://plugins.example.com'));
	}

	public function testRequiresSignatureWhenConfigured(): void
	{
		$downloader = $this->downloader(['signature' => null], requireSignature: true);

		$this->expectException(SecurityViolationException::class);
		$downloader->download('acme-blog', '1.0.0');
	}
}
