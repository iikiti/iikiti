<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Storage;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\AssetStorageProviderInterface;
use iikiti\Extension\StorageS3\Service\AwsSigV4Signer;
use iikiti\Extension\StorageS3\Service\S3StorageConfig;
use iikiti\Extension\StorageS3\Service\S3StorageConfigResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Registers the Google Cloud Storage S3-interop preset (`gcs`) backed by {@see S3AssetStorage}.
 */
final readonly class GcsAssetStorageProvider implements AssetStorageProviderInterface
{
	public function __construct(
		private S3StorageConfigResolver $configResolver,
		private HttpClientInterface $httpClient,
		private AwsSigV4Signer $signer,
		#[Autowire('%kernel.cache_dir%')]
		private string $cacheDirectory,
		private ?AssetMimeTypeRegistry $mimeRegistry = null,
	) {
	}

	public function getDriverName(): string
	{
		return S3StorageConfig::PROVIDER_GCS;
	}

	public function createStorage(): AssetStorageInterface
	{
		return new S3AssetStorage(
			config: $this->configResolver->resolve(S3StorageConfig::PROVIDER_GCS),
			httpClient: $this->httpClient,
			signer: $this->signer,
			cacheDirectory: $this->cacheDirectory,
			mimeRegistry: $this->mimeRegistry,
		);
	}

	public function isConfigured(): bool
	{
		return $this->configResolver->resolve(S3StorageConfig::PROVIDER_GCS)->isConfigured();
	}
}
