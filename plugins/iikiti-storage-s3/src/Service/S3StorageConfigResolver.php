<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Secrets\SecretsApi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves {@see S3StorageConfig} from the current application plugin configuration
 * and {@see SecretsApi}, falling back to `ASSET_S3_*` environment variables.
 */
final readonly class S3StorageConfigResolver
{
	public function __construct(
		private ?ApplicationRegistry $applicationRegistry = null,
		private ?ApplicationRepository $applicationRepository = null,
		private ?SecretsApi $secretsApi = null,
		#[Autowire('%asset_s3_endpoint%')]
		private string $envEndpoint = '',
		#[Autowire('%asset_s3_region%')]
		private string $envRegion = 'us-east-1',
		#[Autowire('%asset_s3_bucket%')]
		private string $envBucket = '',
		#[Autowire('%asset_s3_access_key%')]
		private string $envAccessKey = '',
		#[Autowire('%asset_s3_secret_key%')]
		private string $envSecretKey = '',
		#[Autowire('%asset_s3_prefix%')]
		private string $envPrefix = '',
		#[Autowire('%asset_s3_path_style%')]
		private bool $envPathStyle = false,
		#[Autowire('%asset_s3_cdn_url%')]
		private string $envCdnUrl = '',
	) {
	}

	public function resolve(?string $providerOverride = null): S3StorageConfig
	{
		return $this->resolveForApplication($this->findCurrentApplication(), $providerOverride);
	}

	public function resolveForApplication(?Application $application, ?string $providerOverride = null): S3StorageConfig
	{
		return S3StorageConfig::fromApplicationAndEnv(
			application: $application,
			secretsApi: $this->secretsApi,
			envDefaults: [
				'endpoint' => $this->envEndpoint,
				'region' => $this->envRegion,
				'bucket' => $this->envBucket,
				'accessKey' => $this->envAccessKey,
				'secretKey' => $this->envSecretKey,
				'prefix' => $this->envPrefix,
				'pathStyle' => $this->envPathStyle,
				'cdnUrl' => $this->envCdnUrl,
			],
			providerOverride: $providerOverride,
		);
	}

	private function findCurrentApplication(): ?Application
	{
		try {
			if (null !== $this->applicationRegistry && SiteRegistry::isInitialized() && SiteRegistry::hasCurrent() && $this->applicationRegistry->hasCurrentApplication()) {
				return $this->applicationRegistry->getCurrent();
			}
			if (null !== $this->applicationRepository) {
				$application = $this->applicationRepository->findOneBy([], ['id' => 'ASC']);
				if ($application instanceof Application) {
					return $application;
				}
			}
		} catch (\Throwable) {
			// Fall back to environment defaults if the database or request context is unavailable.
		}

		return null;
	}
}
