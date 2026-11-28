<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Service;

/**
 * Pure-PHP AWS Signature Version 4 request signer and query-string presigner
 * for S3-compatible object storage backends (AWS S3, Cloudflare R2, MinIO,
 * DigitalOcean Spaces, and Google Cloud Storage S3-interop).
 */
final class AwsSigV4Signer
{
	public const ALGORITHM = 'AWS4-HMAC-SHA256';
	public const SERVICE = 's3';
	public const TERMINATOR = 'aws4_request';
	public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
	public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	public const MAX_PRESIGN_EXPIRES = 604800;

	/**
	 * Signs an HTTP request using AWS Signature Version 4 Authorization header.
	 *
	 * @param array<string,string> $headers
	 *
	 * @return array<string,string> Complete header map including Host, X-Amz-Date, X-Amz-Content-Sha256, and Authorization
	 */
	public function signRequest(
		string $method,
		string $url,
		string $accessKey,
		string $secretKey,
		string $region,
		array $headers = [],
		?string $payloadHash = null,
		?\DateTimeImmutable $now = null,
		string $service = self::SERVICE,
	): array {
		$this->assertCredentials($accessKey, $secretKey, $region);

		$normalizedMethod = strtoupper(trim($method));
		if ('' === $normalizedMethod || 1 !== preg_match('/^[A-Z]+$/', $normalizedMethod)) {
			throw new \InvalidArgumentException('HTTP method must be a non-empty uppercase token.');
		}

		$parts = $this->parseUrlParts($url);
		$timestamp = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
		$amzDate = $timestamp->format('Ymd\THis\Z');
		$dateStamp = $timestamp->format('Ymd');
		$resolvedPayloadHash = null !== $payloadHash && '' !== trim($payloadHash) ? trim($payloadHash) : self::EMPTY_SHA256;

		$canonicalUri = $this->canonicalizeUri($parts['path']);
		$canonicalQueryString = $this->canonicalizeQueryPairs($this->parseRawQueryString($parts['query']));

		/** @var array<string,string> $canonicalHeaders */
		$canonicalHeaders = [];
		/** @var array<string,string> $outputHeaders */
		$outputHeaders = [];

		foreach ($headers as $name => $value) {
			$lower = strtolower(trim($name));
			if ('' === $lower || in_array($lower, ['host', 'x-amz-date', 'x-amz-content-sha256', 'authorization'], true)) {
				continue;
			}
			$cleanValue = $this->normalizeHeaderValue($value);
			$canonicalHeaders[$lower] = $cleanValue;
			$outputHeaders[trim($name)] = $cleanValue;
		}

		$canonicalHeaders['host'] = $parts['hostHeader'];
		$canonicalHeaders['x-amz-date'] = $amzDate;
		$canonicalHeaders['x-amz-content-sha256'] = $resolvedPayloadHash;

		ksort($canonicalHeaders, SORT_STRING);

		$canonicalHeadersBlock = '';
		foreach ($canonicalHeaders as $name => $value) {
			$canonicalHeadersBlock .= $name.':'.$value."\n";
		}
		$signedHeaders = implode(';', array_keys($canonicalHeaders));

		$canonicalRequest = $this->buildCanonicalRequest(
			$normalizedMethod,
			$canonicalUri,
			$canonicalQueryString,
			$canonicalHeadersBlock,
			$signedHeaders,
			$resolvedPayloadHash,
		);

		$credentialScope = sprintf('%s/%s/%s/%s', $dateStamp, $region, $service, self::TERMINATOR);
		$stringToSign = sprintf(
			"%s\n%s\n%s\n%s",
			self::ALGORITHM,
			$amzDate,
			$credentialScope,
			hash('sha256', $canonicalRequest),
		);

		$signature = $this->calculateSignature($secretKey, $dateStamp, $region, $service, $stringToSign);

		$outputHeaders['Host'] = $parts['hostHeader'];
		$outputHeaders['X-Amz-Date'] = $amzDate;
		$outputHeaders['X-Amz-Content-Sha256'] = $resolvedPayloadHash;
		$outputHeaders['Authorization'] = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$accessKey,
			$credentialScope,
			$signedHeaders,
			$signature,
		);

		return $outputHeaders;
	}

	/**
	 * Generates an AWS Signature Version 4 query-string presigned GET URL.
	 *
	 * @param array<string,string> $queryParams Optional extra query parameters (e.g. response-content-disposition, response-content-type)
	 */
	public function presignGetUrl(
		string $url,
		string $accessKey,
		string $secretKey,
		string $region,
		int $expiresInSeconds,
		array $queryParams = [],
		?\DateTimeImmutable $now = null,
		string $service = self::SERVICE,
	): string {
		$this->assertCredentials($accessKey, $secretKey, $region);

		if ($expiresInSeconds < 1 || $expiresInSeconds > self::MAX_PRESIGN_EXPIRES) {
			throw new \InvalidArgumentException(sprintf('Presigned URL expiry must be between 1 and %d seconds.', self::MAX_PRESIGN_EXPIRES));
		}

		$parts = $this->parseUrlParts($url);
		$timestamp = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
		$amzDate = $timestamp->format('Ymd\THis\Z');
		$dateStamp = $timestamp->format('Ymd');

		$credentialScope = sprintf('%s/%s/%s/%s', $dateStamp, $region, $service, self::TERMINATOR);
		$canonicalUri = $this->canonicalizeUri($parts['path']);

		$pairs = $this->parseRawQueryString($parts['query']);
		foreach ($queryParams as $key => $value) {
			$pairs[] = [$key, $value];
		}
		$pairs[] = ['X-Amz-Algorithm', self::ALGORITHM];
		$pairs[] = ['X-Amz-Credential', $accessKey.'/'.$credentialScope];
		$pairs[] = ['X-Amz-Date', $amzDate];
		$pairs[] = ['X-Amz-Expires', (string) $expiresInSeconds];
		$pairs[] = ['X-Amz-SignedHeaders', 'host'];

		$canonicalQueryString = $this->canonicalizeQueryPairs($pairs);
		$canonicalHeadersBlock = 'host:'.$parts['hostHeader']."\n";

		$canonicalRequest = $this->buildCanonicalRequest(
			'GET',
			$canonicalUri,
			$canonicalQueryString,
			$canonicalHeadersBlock,
			'host',
			self::UNSIGNED_PAYLOAD,
		);

		$stringToSign = sprintf(
			"%s\n%s\n%s\n%s",
			self::ALGORITHM,
			$amzDate,
			$credentialScope,
			hash('sha256', $canonicalRequest),
		);

		$signature = $this->calculateSignature($secretKey, $dateStamp, $region, $service, $stringToSign);

		return sprintf(
			'%s://%s%s?%s&X-Amz-Signature=%s',
			$parts['scheme'],
			$parts['hostHeader'],
			$canonicalUri,
			$canonicalQueryString,
			$signature,
		);
	}

	/**
	 * RFC 3986 percent-encoding required by AWS SigV4.
	 */
	public function uriEncode(string $value, bool $encodeSlash = true): string
	{
		$encoded = rawurlencode($value);

		return $encodeSlash ? $encoded : str_replace('%2F', '/', $encoded);
	}

	/**
	 * Builds the canonical URI by RFC 3986 encoding each path segment.
	 */
	public function canonicalizeUri(string $path): string
	{
		if ('' === $path || '/' === $path) {
			return '/';
		}

		$normalized = str_starts_with($path, '/') ? $path : '/'.$path;
		$segments = explode('/', $normalized);
		$encodedSegments = array_map(
			fn (string $segment): string => '' === $segment ? '' : $this->uriEncode(rawurldecode($segment), true),
			$segments,
		);

		return implode('/', $encodedSegments);
	}

	private function buildCanonicalRequest(
		string $method,
		string $canonicalUri,
		string $canonicalQueryString,
		string $canonicalHeadersBlock,
		string $signedHeaders,
		string $payloadHash,
	): string {
		return sprintf(
			"%s\n%s\n%s\n%s\n%s\n%s",
			$method,
			$canonicalUri,
			$canonicalQueryString,
			$canonicalHeadersBlock,
			$signedHeaders,
			$payloadHash,
		);
	}

	private function calculateSignature(
		string $secretKey,
		string $dateStamp,
		string $region,
		string $service,
		string $stringToSign,
	): string {
		$kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$secretKey, true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		$kSigning = hash_hmac('sha256', self::TERMINATOR, $kService, true);

		return hash_hmac('sha256', $stringToSign, $kSigning);
	}

	/**
	 * @return array{scheme:string,hostHeader:string,path:string,query:string}
	 */
	private function parseUrlParts(string $url): array
	{
		$parts = parse_url($url);
		if (!is_array($parts)) {
			throw new \InvalidArgumentException('Invalid S3 request URL.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host = strtolower((string) ($parts['host'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true) || '' === $host) {
			throw new \InvalidArgumentException('S3 request URL must include an HTTP(S) scheme and host.');
		}

		$port = $parts['port'] ?? null;
		$includePort = is_int($port) &&
			!(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port));
		$hostHeader = $includePort ? $host.':'.$port : $host;

		return [
			'scheme' => $scheme,
			'hostHeader' => $hostHeader,
			'path' => (string) ($parts['path'] ?? '/'),
			'query' => (string) ($parts['query'] ?? ''),
		];
	}

	/**
	 * Parses a raw URL query string without PHP's parse_str() key mangling.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	private function parseRawQueryString(string $query): array
	{
		if ('' === $query) {
			return [];
		}

		$pairs = [];
		foreach (explode('&', $query) as $part) {
			if ('' === $part) {
				continue;
			}
			$eqPos = strpos($part, '=');
			if (false === $eqPos) {
				$pairs[] = [rawurldecode($part), ''];
			} else {
				$pairs[] = [
					rawurldecode(substr($part, 0, $eqPos)),
					rawurldecode(substr($part, $eqPos + 1)),
				];
			}
		}

		return $pairs;
	}

	/**
	 * @param list<array{0:string,1:string}> $pairs
	 */
	private function canonicalizeQueryPairs(array $pairs): string
	{
		if ([] === $pairs) {
			return '';
		}

		$encoded = [];
		foreach ($pairs as [$key, $value]) {
			$encoded[] = [
				$this->uriEncode($key, true),
				$this->uriEncode($value, true),
			];
		}

		usort($encoded, static function (array $left, array $right): int {
			$keyOrder = strcmp($left[0], $right[0]);

			return 0 !== $keyOrder ? $keyOrder : strcmp($left[1], $right[1]);
		});

		return implode('&', array_map(
			static fn (array $pair): string => $pair[0].'='.$pair[1],
			$encoded,
		));
	}

	private function normalizeHeaderValue(string $value): string
	{
		$collapsed = preg_replace('/[ \t]+/', ' ', trim($value));

		return is_string($collapsed) ? $collapsed : trim($value);
	}

	private function assertCredentials(string $accessKey, string $secretKey, string $region): void
	{
		if ('' === trim($accessKey) || '' === trim($secretKey)) {
			throw new \InvalidArgumentException('AWS SigV4 access key and secret key must be non-empty.');
		}
		if ('' === trim($region)) {
			throw new \InvalidArgumentException('AWS SigV4 region must be non-empty.');
		}
	}
}
