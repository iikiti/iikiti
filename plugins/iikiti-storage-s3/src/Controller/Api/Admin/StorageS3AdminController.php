<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Secrets\SecretsApi;
use iikiti\CMS\Security\SiteAccessPolicy;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageRegistry;
use iikiti\Extension\StorageS3\Service\AwsSigV4Signer;
use iikiti\Extension\StorageS3\Service\S3StorageConfig;
use iikiti\Extension\StorageS3\Service\S3StorageConfigResolver;
use iikiti\Extension\StorageS3\Storage\S3AssetStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Admin API controller for reading, updating, and testing S3-compatible
 * object storage plugin configuration.
 */
#[AsController]
#[RequiresPlugin(S3StorageConfig::PLUGIN_SLUG)]
final class StorageS3AdminController extends AbstractController
{
	private const MAX_BODY_BYTES = 16384;

	/** @var list<string> */
	private const ALLOWED_FIELDS = [
		'provider',
		'endpoint',
		'region',
		'bucket',
		'accessKey',
		'secretKey',
		'clearSecretKey',
		'prefix',
		'pathStyle',
		'cdnUrl',
	];

	public function __construct(
		private readonly ApplicationRegistry $applicationRegistry,
		private readonly ApplicationRepository $applicationRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly SecretsApi $secretsApi,
		private readonly S3StorageConfigResolver $configResolver,
		private readonly AssetStorageRegistry $storageRegistry,
		private readonly HttpClientInterface $httpClient,
		private readonly AwsSigV4Signer $signer,
		#[Autowire('%kernel.cache_dir%')]
		private readonly string $cacheDirectory,
		private readonly SiteAccessPolicy $siteAccessPolicy,
		private readonly ?AssetMimeTypeRegistry $mimeRegistry = null,
		private readonly ?AuditRecorder $auditRecorder = null,
	) {
	}

	#[Route('/api/admin/plugins/iikiti-storage-s3/config', name: 'api_admin_plugin_storage_s3_config_get', methods: ['GET'])]
	public function getConfig(): JsonResponse
	{
		$this->requireGlobalAdmin();

		$application = $this->resolveApplication();
		$config = $this->configResolver->resolveForApplication($application);

		return new JsonResponse([
			'plugin' => S3StorageConfig::PLUGIN_SLUG,
			'activeDriver' => $this->storageRegistry->getActiveDriverName(),
			'availableDrivers' => $this->storageRegistry->getDrivers(),
			'config' => $config->toSanitizedArray(),
		]);
	}

	#[Route('/api/admin/plugins/iikiti-storage-s3/config', name: 'api_admin_plugin_storage_s3_config_put', methods: ['PUT'])]
	public function updateConfig(Request $request): JsonResponse
	{
		$this->requireGlobalAdmin();

		$application = $this->resolveApplication();
		$existing = $this->configResolver->resolveForApplication($application);

		try {
			$payload = $this->decodeJsonObject($request);
			$validated = $this->validateConfigPayload($payload, $existing);
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$configuration = $application->getConfiguration();
		$configuration->setPluginConfiguration(S3StorageConfig::PLUGIN_SLUG, $validated['settings']);
		$application->setConfiguration($configuration);

		if ($validated['clearSecretKey']) {
			$this->secretsApi->delete($application, S3StorageConfig::SECRET_KEY_PROPERTY);
		} elseif (null !== $validated['secretKey']) {
			$this->secretsApi->set($application, S3StorageConfig::SECRET_KEY_PROPERTY, $validated['secretKey']);
		}

		$this->entityManager->persist($application);
		$this->entityManager->flush();

		$this->auditRecorder?->record(
			summary: sprintf('Updated Object Storage (%s) configuration for Application #%s', $validated['settings']['provider'], (string) ($application->getId() ?? '0')),
			action: 'updated_configuration',
			objectType: 'Application',
			objectId: $application->getId(),
			details: [
				'plugin' => S3StorageConfig::PLUGIN_SLUG,
				'provider' => $validated['settings']['provider'],
				'bucket' => $validated['settings']['bucket'],
				'secretUpdated' => $validated['clearSecretKey'] || null !== $validated['secretKey'],
			],
		);

		$updated = $this->configResolver->resolveForApplication($application);

		return new JsonResponse([
			'plugin' => S3StorageConfig::PLUGIN_SLUG,
			'activeDriver' => $this->storageRegistry->getActiveDriverName(),
			'availableDrivers' => $this->storageRegistry->getDrivers(),
			'config' => $updated->toSanitizedArray(),
		]);
	}

	#[Route('/api/admin/plugins/iikiti-storage-s3/test', name: 'api_admin_plugin_storage_s3_test', methods: ['POST'])]
	public function testConnection(Request $request): JsonResponse
	{
		$this->requireGlobalAdmin();

		$writeProbe = true;
		$rawContent = trim($request->getContent());
		if ('' !== $rawContent) {
			try {
				$payload = $this->decodeJsonObject($request);
				foreach (array_keys($payload) as $field) {
					if ('writeProbe' !== $field) {
						throw new \InvalidArgumentException(sprintf('Unknown test field "%s".', $field));
					}
				}
				if (array_key_exists('writeProbe', $payload)) {
					if (!is_bool($payload['writeProbe'])) {
						throw new \InvalidArgumentException('Field "writeProbe" must be a boolean.');
					}
					$writeProbe = $payload['writeProbe'];
				}
			} catch (\InvalidArgumentException $exception) {
				return new JsonResponse(['ok' => false, 'error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
			}
		}

		$application = $this->resolveApplication();
		$config = $this->configResolver->resolveForApplication($application);
		if (!$config->isConfigured()) {
			return new JsonResponse([
				'ok' => false,
				'error' => 'Object storage configuration is incomplete. Configure endpoint, bucket, accessKey, and secretKey first.',
			], JsonResponse::HTTP_BAD_REQUEST);
		}

		try {
			S3StorageConfig::assertSafeEndpointUrl($config->resolvedEndpoint(), 'endpoint', resolveDns: true);
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse([
				'ok' => false,
				'error' => $exception->getMessage(),
			], JsonResponse::HTTP_BAD_REQUEST);
		}

		$storage = new S3AssetStorage(
			config: $config,
			httpClient: $this->httpClient,
			signer: $this->signer,
			cacheDirectory: $this->cacheDirectory,
			mimeRegistry: $this->mimeRegistry,
		);

		try {
			$result = $storage->probeBucket($writeProbe);
		} catch (\Throwable $exception) {
			return new JsonResponse([
				'ok' => false,
				'provider' => $config->provider,
				'bucket' => $config->bucket,
				'endpoint' => $config->resolvedEndpoint(),
				'error' => $exception->getMessage(),
			], JsonResponse::HTTP_BAD_GATEWAY);
		}

		return new JsonResponse($result, $result['ok'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_BAD_GATEWAY);
	}

	private function requireGlobalAdmin(): void
	{
		$this->denyAccessUnlessGranted('ROLE_ADMIN');
		$user = $this->getUser();
		if (!$user instanceof User || !$this->siteAccessPolicy->isGlobalAdmin($user)) {
			throw $this->createAccessDeniedException('Global administrator privileges are required to manage object storage configuration.');
		}
	}

	private function resolveApplication(): Application
	{
		if (SiteRegistry::isInitialized() && SiteRegistry::hasCurrent() && $this->applicationRegistry->hasCurrentApplication()) {
			return $this->applicationRegistry->getCurrent();
		}

		$application = $this->applicationRepository->findOneBy([], ['id' => 'ASC']);
		if ($application instanceof Application) {
			return $application;
		}

		throw new NotFoundHttpException('Application not found.');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decodeJsonObject(Request $request): array
	{
		$content = $request->getContent();
		if (strlen($content) > self::MAX_BODY_BYTES) {
			throw new \InvalidArgumentException(sprintf('Request body exceeds %d bytes.', self::MAX_BODY_BYTES));
		}

		try {
			$decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Invalid JSON request body.', 0, $exception);
		}

		if (!is_array($decoded) || (array_is_list($decoded) && [] !== $decoded)) {
			throw new \InvalidArgumentException('Request body must be a JSON object.');
		}

		/** @var array<string,mixed> $result */
		$result = [];
		foreach ($decoded as $key => $value) {
			if (!is_string($key)) {
				throw new \InvalidArgumentException('Request body keys must be strings.');
			}
			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $payload
	 *
	 * @return array{
	 *     settings: array{
	 *         provider: string,
	 *         endpoint: string,
	 *         region: string,
	 *         bucket: string,
	 *         accessKey: string,
	 *         prefix: string,
	 *         pathStyle: bool,
	 *         cdnUrl: string,
	 *     },
	 *     secretKey: ?string,
	 *     clearSecretKey: bool,
	 * }
	 */
	private function validateConfigPayload(array $payload, S3StorageConfig $existing): array
	{
		foreach (array_keys($payload) as $field) {
			if (!in_array($field, self::ALLOWED_FIELDS, true)) {
				throw new \InvalidArgumentException(sprintf('Unknown configuration field "%s".', $field));
			}
		}

		$provider = $existing->provider;
		if (array_key_exists('provider', $payload)) {
			$provider = strtolower($this->stringField($payload['provider'], 'provider', 16));
			if (!in_array($provider, S3StorageConfig::ALLOWED_PROVIDERS, true)) {
				throw new \InvalidArgumentException('Field "provider" must be one of s3, r2, or gcs.');
			}
		}

		$endpoint = array_key_exists('endpoint', $payload) ?
			$this->urlField($payload['endpoint'], 'endpoint') :
			$existing->endpoint;

		$region = array_key_exists('region', $payload) ?
			$this->stringField($payload['region'], 'region', 64) :
			$existing->region;
		if ('' !== $region && 1 !== preg_match('/^[a-z0-9-]{1,64}$/i', $region)) {
			throw new \InvalidArgumentException('Field "region" must contain only letters, digits, and hyphens.');
		}

		$bucket = array_key_exists('bucket', $payload) ?
			$this->stringField($payload['bucket'], 'bucket', 63) :
			$existing->bucket;
		if ('' !== $bucket && 1 !== preg_match('/^[a-z0-9][a-z0-9._-]{1,62}$/', $bucket)) {
			throw new \InvalidArgumentException('Field "bucket" must be a valid S3-compatible bucket name.');
		}

		$accessKey = array_key_exists('accessKey', $payload) ?
			$this->stringField($payload['accessKey'], 'accessKey', 256) :
			$existing->accessKey;

		$prefix = array_key_exists('prefix', $payload) ?
			trim(str_replace('\\', '/', $this->stringField($payload['prefix'], 'prefix', 256)), '/') :
			$existing->prefix;
		if ('' !== $prefix && (str_contains($prefix, '..') || 1 !== preg_match('/^[A-Za-z0-9._\/-]+$/', $prefix))) {
			throw new \InvalidArgumentException('Field "prefix" contains invalid path characters.');
		}

		$pathStyle = $existing->pathStyle;
		if (array_key_exists('pathStyle', $payload)) {
			if (!is_bool($payload['pathStyle'])) {
				throw new \InvalidArgumentException('Field "pathStyle" must be a boolean.');
			}
			$pathStyle = $payload['pathStyle'];
		}

		$cdnUrl = array_key_exists('cdnUrl', $payload) ?
			$this->urlField($payload['cdnUrl'], 'cdnUrl') :
			$existing->cdnUrl;

		$clearSecretKey = false;
		if (array_key_exists('clearSecretKey', $payload)) {
			if (!is_bool($payload['clearSecretKey'])) {
				throw new \InvalidArgumentException('Field "clearSecretKey" must be a boolean.');
			}
			$clearSecretKey = $payload['clearSecretKey'];
		}

		$secretKey = null;
		if (array_key_exists('secretKey', $payload) && null !== $payload['secretKey']) {
			$candidate = $this->stringField($payload['secretKey'], 'secretKey', 512);
			if ('' !== $candidate) {
				$secretKey = $candidate;
			}
		}

		return [
			'settings' => [
				'provider' => $provider,
				'endpoint' => $endpoint,
				'region' => $region,
				'bucket' => $bucket,
				'accessKey' => $accessKey,
				'prefix' => $prefix,
				'pathStyle' => $pathStyle,
				'cdnUrl' => $cdnUrl,
			],
			'secretKey' => $secretKey,
			'clearSecretKey' => $clearSecretKey,
		];
	}

	private function stringField(mixed $value, string $field, int $maxLength): string
	{
		if (!is_string($value) || 1 === preg_match('/[\x00-\x1f\x7f]/', $value)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a valid string.', $field));
		}
		$trimmed = trim($value);
		if (strlen($trimmed) > $maxLength) {
			throw new \InvalidArgumentException(sprintf('Field "%s" exceeds maximum length of %d characters.', $field, $maxLength));
		}

		return $trimmed;
	}

	private function urlField(mixed $value, string $field): string
	{
		$url = rtrim($this->stringField($value, $field, 512), '/');
		if ('' === $url) {
			return '';
		}

		return S3StorageConfig::assertSafeEndpointUrl($url, $field, resolveDns: true);
	}
}
