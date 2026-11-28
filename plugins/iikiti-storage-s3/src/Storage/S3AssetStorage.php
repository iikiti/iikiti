<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Storage;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use iikiti\CMS\Storage\LocalTempFile;
use iikiti\Extension\StorageS3\Service\AwsSigV4Signer;
use iikiti\Extension\StorageS3\Service\S3StorageConfig;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * S3-compatible remote {@see AssetStorageInterface} implementation powered by
 * Symfony's {@see HttpClientInterface} and pure-PHP {@see AwsSigV4Signer}.
 */
#[Exclude]
final class S3AssetStorage implements AssetStorageInterface
{
	private const KEY_PATTERN = '~^[a-f0-9]{2}/[a-f0-9]{64}\.[a-z0-9]{1,12}$~';
	private const IN_MEMORY_BUFFER_BYTES = 2097152;
	private const TEMP_SUBDIR = 'asset-storage-tmp';
	private const REQUEST_TIMEOUT_SECONDS = 30.0;
	private const MAX_REDIRECTS = 0;

	private readonly string $tempDirectory;
	private readonly AssetMimeTypeRegistry $mimeRegistry;
	private readonly Filesystem $filesystem;

	public function __construct(
		private readonly S3StorageConfig $config,
		private readonly HttpClientInterface $httpClient,
		private readonly AwsSigV4Signer $signer = new AwsSigV4Signer(),
		?string $cacheDirectory = null,
		?AssetMimeTypeRegistry $mimeRegistry = null,
		?Filesystem $filesystem = null,
	) {
		$baseDir = null !== $cacheDirectory && '' !== trim($cacheDirectory) ?
			rtrim($cacheDirectory, '/') :
			rtrim(sys_get_temp_dir(), '/');
		$this->tempDirectory = $baseDir.'/'.self::TEMP_SUBDIR;
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
		$this->filesystem = $filesystem ?? new Filesystem();
	}

	public function getDriverName(): string
	{
		return $this->config->provider;
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$handle = @fopen($file->getPathname(), 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open uploaded asset file for reading.');
		}

		try {
			$this->writeStream($key, $handle, $mimeType);
		} finally {
			fclose($handle);
		}

		return $key;
	}

	public function createTemporaryPath(string $mimeType): string
	{
		$extension = str_contains($mimeType, '/') ?
			$this->mimeRegistry->extensionFor($mimeType) :
			strtolower(trim($mimeType));
		if (1 !== preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
			throw new \InvalidArgumentException('Invalid temporary file extension.');
		}

		$this->filesystem->mkdir($this->tempDirectory, 0700);

		return $this->tempDirectory.'/'.bin2hex(random_bytes(32)).'.'.$extension;
	}

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
	{
		if (!is_file($temporaryPath)) {
			throw new \InvalidArgumentException('Temporary asset file does not exist.');
		}

		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$handle = @fopen($temporaryPath, 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open temporary asset file for reading.');
		}

		try {
			$this->writeStream($key, $handle, $mimeType);
		} finally {
			fclose($handle);
		}

		$this->filesystem->remove($temporaryPath);

		return $key;
	}

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
	{
		$this->assertValidKey($key);
		if (!is_resource($stream)) {
			throw new \InvalidArgumentException('Stream must be a valid readable resource.');
		}
		if (null !== $mimeType && !$this->mimeRegistry->isAllowed($mimeType)) {
			throw new \InvalidArgumentException('Unsupported asset file type.');
		}

		$buffer = fopen('php://temp/maxmemory:'.self::IN_MEMORY_BUFFER_BYTES, 'r+b');
		if (false === $buffer) {
			throw new \RuntimeException('Unable to allocate temporary upload stream.');
		}

		$hashContext = hash_init('sha256');
		$byteLength = 0;

		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 65536);
				if (false === $chunk) {
					throw new \RuntimeException('Unable to read from source asset stream.');
				}
				if ('' === $chunk) {
					break;
				}
				hash_update($hashContext, $chunk);
				$written = fwrite($buffer, $chunk);
				if (strlen($chunk) !== $written) {
					throw new \RuntimeException('Unable to buffer asset stream for upload.');
				}
				$byteLength += strlen($chunk);
			}

			$payloadHash = hash_final($hashContext);
			rewind($buffer);

			$url = $this->config->buildObjectUrl($key);
			$headers = $this->signer->signRequest(
				method: 'PUT',
				url: $url,
				accessKey: $this->config->accessKey,
				secretKey: $this->config->secretKey,
				region: $this->config->resolvedRegion(),
				headers: [
					'Content-Type' => $mimeType ?? 'application/octet-stream',
					'Content-Length' => (string) $byteLength,
				],
				payloadHash: $payloadHash,
			);

			$body = $byteLength <= self::IN_MEMORY_BUFFER_BYTES ? (string) stream_get_contents($buffer) : $buffer;
			$response = $this->httpClient->request('PUT', $url, [
				'headers' => $headers,
				'body' => $body,
				'max_redirects' => self::MAX_REDIRECTS,
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
			]);

			$status = $response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				throw new \RuntimeException(sprintf('Object storage PUT failed for key "%s" with HTTP %d.', $key, $status));
			}
		} finally {
			fclose($buffer);
		}
	}

	/**
	 * @return resource
	 */
	public function readStream(string $key): mixed
	{
		$this->assertValidKey($key);
		$url = $this->config->buildObjectUrl($key);
		$headers = $this->signer->signRequest(
			method: 'GET',
			url: $url,
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			payloadHash: AwsSigV4Signer::EMPTY_SHA256,
		);

		$response = $this->httpClient->request('GET', $url, [
			'headers' => $headers,
			'max_redirects' => self::MAX_REDIRECTS,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
		]);

		$status = $response->getStatusCode();
		if (404 === $status) {
			throw new \RuntimeException('Stored asset file does not exist.');
		}
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException(sprintf('Object storage GET failed for key "%s" with HTTP %d.', $key, $status));
		}

		$buffer = fopen('php://temp/maxmemory:'.self::IN_MEMORY_BUFFER_BYTES, 'r+b');
		if (false === $buffer) {
			throw new \RuntimeException('Unable to allocate temporary download stream.');
		}

		try {
			foreach ($this->httpClient->stream($response) as $chunk) {
				$content = $chunk->getContent();
				if ('' === $content) {
					continue;
				}
				if (strlen($content) !== fwrite($buffer, $content)) {
					throw new \RuntimeException('Unable to buffer downloaded asset stream.');
				}
			}
			rewind($buffer);
		} catch (\Throwable $exception) {
			fclose($buffer);
			throw $exception;
		}

		return $buffer;
	}

	public function exists(string $key): bool
	{
		$this->assertValidKey($key);
		$url = $this->config->buildObjectUrl($key);
		$headers = $this->signer->signRequest(
			method: 'HEAD',
			url: $url,
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			payloadHash: AwsSigV4Signer::EMPTY_SHA256,
		);

		$response = $this->httpClient->request('HEAD', $url, [
			'headers' => $headers,
			'max_redirects' => self::MAX_REDIRECTS,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
		]);

		$status = $response->getStatusCode();
		if (404 === $status) {
			return false;
		}
		if ($status >= 200 && $status < 300) {
			return true;
		}

		throw new \RuntimeException(sprintf('Object storage HEAD failed for key "%s" with HTTP %d.', $key, $status));
	}

	public function size(string $key): ?int
	{
		$this->assertValidKey($key);
		$url = $this->config->buildObjectUrl($key);
		$headers = $this->signer->signRequest(
			method: 'HEAD',
			url: $url,
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			payloadHash: AwsSigV4Signer::EMPTY_SHA256,
		);

		$response = $this->httpClient->request('HEAD', $url, [
			'headers' => $headers,
			'max_redirects' => self::MAX_REDIRECTS,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
		]);

		$status = $response->getStatusCode();
		if (404 === $status) {
			return null;
		}
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException(sprintf('Object storage HEAD failed for key "%s" with HTTP %d.', $key, $status));
		}

		$responseHeaders = $response->getHeaders(false);
		$contentLength = $responseHeaders['content-length'][0] ?? null;

		return is_string($contentLength) && ctype_digit($contentLength) ? (int) $contentLength : null;
	}

	public function delete(string $key): void
	{
		$this->assertValidKey($key);
		$url = $this->config->buildObjectUrl($key);
		$headers = $this->signer->signRequest(
			method: 'DELETE',
			url: $url,
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			payloadHash: AwsSigV4Signer::EMPTY_SHA256,
		);

		$response = $this->httpClient->request('DELETE', $url, [
			'headers' => $headers,
			'max_redirects' => self::MAX_REDIRECTS,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
		]);

		$status = $response->getStatusCode();
		if (404 === $status || ($status >= 200 && $status < 300)) {
			return;
		}

		throw new \RuntimeException(sprintf('Object storage DELETE failed for key "%s" with HTTP %d.', $key, $status));
	}

	public function pathFor(string $key): string
	{
		$this->assertValidKey($key);

		throw new \LogicException('Remote S3 asset storage does not expose local filesystem paths. Use readStream() or materializeToLocalTemp().');
	}

	public function isLocal(): bool
	{
		return false;
	}

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
	{
		$this->assertValidKey($key);
		$resolvedExtension = null !== $extension && '' !== trim($extension) ?
			strtolower(ltrim(trim($extension), '.')) :
			pathinfo($key, PATHINFO_EXTENSION);
		$tempPath = $this->createTemporaryPath('' !== $resolvedExtension ? $resolvedExtension : 'tmp');

		$source = $this->readStream($key);
		$target = @fopen($tempPath, 'wb');
		if (false === $target) {
			fclose($source);
			throw new \RuntimeException('Unable to open temporary file for materialized asset.');
		}

		try {
			$copied = stream_copy_to_stream($source, $target);
			if (false === $copied) {
				throw new \RuntimeException('Unable to materialize remote asset to local temporary file.');
			}
			fflush($target);
		} catch (\Throwable $exception) {
			fclose($target);
			fclose($source);
			$this->filesystem->remove($tempPath);
			throw $exception;
		}

		fclose($target);
		fclose($source);

		return new LocalTempFile($tempPath, true);
	}

	public function supportsTemporaryUrls(): bool
	{
		return true;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
	{
		$this->assertValidKey($key);
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$ttl = max(1, min(AwsSigV4Signer::MAX_PRESIGN_EXPIRES, $expiresAt->getTimestamp() - $now->getTimestamp()));

		/** @var array<string,string> $queryParams */
		$queryParams = [];
		$responseContentType = $options['response-content-type'] ?? $options['mimeType'] ?? null;
		if (is_string($responseContentType) && '' !== trim($responseContentType)) {
			$queryParams['response-content-type'] = trim($responseContentType);
		}

		if (isset($options['response-content-disposition']) && is_string($options['response-content-disposition']) && '' !== trim($options['response-content-disposition'])) {
			$queryParams['response-content-disposition'] = trim($options['response-content-disposition']);
		} elseif (isset($options['disposition']) && is_string($options['disposition']) && '' !== trim($options['disposition'])) {
			$disposition = 'inline' === strtolower(trim($options['disposition'])) ? 'inline' : 'attachment';
			$filename = isset($options['filename']) && is_string($options['filename']) ? $this->sanitizeFilename($options['filename']) : null;
			$queryParams['response-content-disposition'] = null !== $filename ?
				sprintf('%s; filename="%s"', $disposition, $filename) :
				$disposition;
		}

		return $this->signer->presignGetUrl(
			url: $this->config->buildObjectUrl($key),
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			expiresInSeconds: $ttl,
			queryParams: $queryParams,
			now: $now,
		);
	}

	public function publicUrl(string $key): ?string
	{
		$this->assertValidKey($key);

		return $this->config->buildPublicUrl($key);
	}

	/**
	 * Verifies bucket connectivity with a signed HEAD bucket check and optional
	 * signed PUT + DELETE object probe.
	 *
	 * @return array{
	 *     ok: bool,
	 *     provider: string,
	 *     bucket: string,
	 *     endpoint: string,
	 *     headBucket: array{ok: bool, status: int},
	 *     putProbe: array{ok: bool, skipped?: bool, key?: string},
	 *     deleteProbe: array{ok: bool, skipped?: bool},
	 * }
	 */
	public function probeBucket(bool $writeProbe = true): array
	{
		$bucketUrl = $this->config->buildBucketUrl();
		$headHeaders = $this->signer->signRequest(
			method: 'HEAD',
			url: $bucketUrl,
			accessKey: $this->config->accessKey,
			secretKey: $this->config->secretKey,
			region: $this->config->resolvedRegion(),
			payloadHash: AwsSigV4Signer::EMPTY_SHA256,
		);

		$headResponse = $this->httpClient->request('HEAD', $bucketUrl, [
			'headers' => $headHeaders,
			'max_redirects' => self::MAX_REDIRECTS,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
		]);
		$headStatus = $headResponse->getStatusCode();
		$headOk = $headStatus >= 200 && $headStatus < 300;

		if (!$headOk) {
			return [
				'ok' => false,
				'provider' => $this->config->provider,
				'bucket' => $this->config->bucket,
				'endpoint' => $this->config->resolvedEndpoint(),
				'headBucket' => ['ok' => false, 'status' => $headStatus],
				'putProbe' => ['ok' => false, 'skipped' => true],
				'deleteProbe' => ['ok' => false, 'skipped' => true],
			];
		}

		if (!$writeProbe) {
			return [
				'ok' => true,
				'provider' => $this->config->provider,
				'bucket' => $this->config->bucket,
				'endpoint' => $this->config->resolvedEndpoint(),
				'headBucket' => ['ok' => true, 'status' => $headStatus],
				'putProbe' => ['ok' => true, 'skipped' => true],
				'deleteProbe' => ['ok' => true, 'skipped' => true],
			];
		}

		$probeKey = $this->newKey('txt');
		$probeStream = fopen('php://memory', 'r+b');
		if (false === $probeStream) {
			throw new \RuntimeException('Unable to create probe stream.');
		}
		fwrite($probeStream, 'iikiti-storage-probe');
		rewind($probeStream);

		$putOk = false;
		$deleteOk = false;
		try {
			$this->writeStream($probeKey, $probeStream, 'text/plain');
			$putOk = true;
		} finally {
			fclose($probeStream);
			if ($putOk) {
				$this->delete($probeKey);
				$deleteOk = true;
			}
		}

		return [
			'ok' => $headOk && $putOk && $deleteOk,
			'provider' => $this->config->provider,
			'bucket' => $this->config->bucket,
			'endpoint' => $this->config->resolvedEndpoint(),
			'headBucket' => ['ok' => true, 'status' => $headStatus],
			'putProbe' => ['ok' => $putOk, 'key' => $probeKey],
			'deleteProbe' => ['ok' => $deleteOk],
		];
	}

	private function assertValidKey(string $key): void
	{
		if (1 !== preg_match(self::KEY_PATTERN, $key)) {
			throw new \InvalidArgumentException('Invalid asset storage key.');
		}
	}

	private function newKey(string $extension): string
	{
		$token = bin2hex(random_bytes(32));

		return substr($token, 0, 2).'/'.$token.'.'.$extension;
	}

	private function sanitizeFilename(string $filename): string
	{
		$cleaned = basename(str_replace('\\', '/', $filename));
		$cleaned = preg_replace('/[\x00-\x1f\x7f"\/\\\\;]/', '', $cleaned) ?? '';
		$cleaned = trim($cleaned);

		return '' !== $cleaned ? $cleaned : 'asset';
	}
}
