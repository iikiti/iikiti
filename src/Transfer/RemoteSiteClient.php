<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use iikiti\CMS\Batch\CredentialRefreshRequired;
use iikiti\CMS\Batch\RetryableBatchMessageFailure;
use iikiti\CMS\Batch\BatchMessageSkipped;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;

final class RemoteSiteClient
{
	private const MAX_MANIFEST_LINE_BYTES = 1048576;

	private readonly NoPrivateNetworkHttpClient $client;

	public function __construct(HttpClientInterface $client)
	{
		$this->client = new NoPrivateNetworkHttpClient($client);
	}

	public function normalizeBaseUrl(string $baseUrl): string
	{
		$parts = parse_url(trim($baseUrl));
		if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new \InvalidArgumentException('Source server URL must be an HTTPS origin without credentials, query, or fragment.');
		}
		$port = isset($parts['port']) ? ':'.$parts['port'] : '';
		$path = rtrim((string) ($parts['path'] ?? ''), '/');

		return 'https://'.strtolower($parts['host']).$port.$path;
	}

	/** @return array<string,mixed> */
	public function getExportInfo(string $baseUrl, int|string $exportId, string $token): array
	{
		$response = $this->request($baseUrl, sprintf('/api/admin/site-transfer/exports/%s', rawurlencode((string) $exportId)), $token);
		$status = $response->getStatusCode();
		if (401 === $status) {
			throw new CredentialRefreshRequired('source_token_expired');
		}
		if (403 === $status || 404 === $status) {
			throw new \InvalidArgumentException('The source export is unavailable to this API token.');
		}
		if (200 !== $status) {
			throw new RetryableBatchMessageFailure(sprintf('Source export information returned HTTP %d.', $status));
		}
		return $response->toArray(false);
	}

	/** @return iterable<array<string,mixed>> */
	public function readManifest(string $baseUrl, int|string $exportId, string $token): iterable
	{
		$response = $this->request($baseUrl, sprintf('/api/admin/site-transfer/exports/%s/manifest', rawurlencode((string) $exportId)), $token, 300);
		$this->assertResponse($response, false);
		$buffer = '';
		foreach ($this->client->stream($response, 30) as $chunk) {
			if ($chunk->isTimeout()) {
				continue;
			}
			$buffer .= $chunk->getContent();
			if (strlen($buffer) > self::MAX_MANIFEST_LINE_BYTES && false === strpos($buffer, "\n")) {
				throw new \UnexpectedValueException('A source manifest record exceeds the 1 MiB line limit.');
			}
			while (false !== ($newline = strpos($buffer, "\n"))) {
				$line = trim(substr($buffer, 0, $newline));
				$buffer = substr($buffer, $newline + 1);
				if (strlen($line) > self::MAX_MANIFEST_LINE_BYTES) {
					throw new \UnexpectedValueException('A source manifest record exceeds the 1 MiB line limit.');
				}
				if ('' !== $line) {
					$record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
					if (!is_array($record)) {
						throw new \UnexpectedValueException('The source manifest contains a malformed record.');
					}
					yield $record;
				}
			}
		}
		$line = trim($buffer);
		if ('' !== $line) {
			if (strlen($line) > self::MAX_MANIFEST_LINE_BYTES) {
				throw new \UnexpectedValueException('A source manifest record exceeds the 1 MiB line limit.');
			}
			$record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($record)) {
				throw new \UnexpectedValueException('The source manifest contains a malformed record.');
			}
			yield $record;
		}
	}

	public function downloadAsset(string $baseUrl, int|string $exportId, int|string $assetId, string $token): ResponseInterface
	{
		$response = $this->request($baseUrl, sprintf('/api/admin/site-transfer/exports/%s/assets/%s', rawurlencode((string) $exportId), rawurlencode((string) $assetId)), $token, 120);
		$this->assertResponse($response, true);

		return $response;
	}

	/** @return iterable<ChunkInterface> */
	public function stream(ResponseInterface $response): iterable
	{
		return $this->client->stream($response, 30);
	}

	private function request(string $baseUrl, string $path, string $token, int $timeout = 60): ResponseInterface
	{
		$origin = $this->normalizeBaseUrl($baseUrl);
		if ('' === $token || strlen($token) > 4096 || str_starts_with($path, '//')) {
			throw new \InvalidArgumentException('Invalid source request.');
		}

		return $this->client->request('GET', $origin.$path, [
			'headers' => ['X-AUTH-TOKEN' => $token, 'Accept' => 'application/json, application/x-ndjson, application/octet-stream'],
			'max_redirects' => 0,
			'timeout' => $timeout,
		]);
	}

	private function assertResponse(ResponseInterface $response, bool $asset): void
	{
		$status = $response->getStatusCode();
		if (401 === $status) {
			throw new CredentialRefreshRequired('source_token_expired');
		}
		if (403 === $status || 404 === $status) {
			if (!$asset) {
				throw new \InvalidArgumentException(403 === $status ? 'Source export access was denied.' : 'Source export manifest was not found.');
			}
			throw new BatchMessageSkipped(403 === $status ? 'Source denied access to this asset.' : 'Source asset is unavailable.');
		}
		if (200 !== $status) {
			throw new RetryableBatchMessageFailure(sprintf('Source %s request returned HTTP %d.', $asset ? 'asset' : 'manifest', $status));
		}
	}
}
