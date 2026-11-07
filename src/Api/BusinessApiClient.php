<?php

declare(strict_types=1);

namespace iikiti\CMS\Api;

use iikiti\CMS\Async\FiberHttpClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** HTTP transport for the application's resource and business-action API. */
final readonly class BusinessApiClient
{
	public function __construct(
		private HttpClientInterface $client,
		#[Autowire('%env(string:INTERNAL_API_BASE_URL)%')]
		private string $baseUrl,
		#[Autowire('%kernel.environment%')]
		private string $environment,
	) {
		$this->assertBaseUrl($baseUrl);
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string|int|float|bool|list<string|int>|null> $query
	 * @param array<string,string> $headers
	 * @return array<mixed>
	 */
	public function request(
		string $method,
		string $path,
		?string $token = null,
		?array $body = null,
		array $query = [],
		array $headers = [],
		float $timeout = 5.0,
	): array {
		try {
			$response = $this->client->request(
				strtoupper($method),
				$this->url($path),
				$this->options($token, $body, $query, $headers, $timeout),
			);
		} catch (HttpExceptionInterface $exception) {
			throw $this->translateHttpException($exception);
		}

		return $this->decode($response);
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string|int|float|bool|list<string|int>|null> $query
	 * @param array<string,string> $headers
	 * @return array<mixed>
	 */
	public function requestAsync(
		FiberHttpClient $http,
		string $method,
		string $path,
		?string $token = null,
		?array $body = null,
		array $query = [],
		array $headers = [],
		float $timeout = 5.0,
	): array {
		try {
			$response = $http->request(
				strtoupper($method),
				$this->url($path),
				$this->options($token, $body, $query, $headers, $timeout),
			);
		} catch (HttpExceptionInterface $exception) {
			throw $this->translateHttpException($exception);
		}

		return $this->decode($response);
	}

	public function getBaseUrl(): string
	{
		return rtrim($this->baseUrl, '/');
	}

	/**
	 * @param array<string,string|int|float|bool|list<string|int>|null> $query
	 * @param array<string,string> $headers
	 * @return array{status:int,headers:array<string,list<string>>,body:string}
	 */
	public function requestRawAsync(
		FiberHttpClient $http,
		string $method,
		string $path,
		?string $token = null,
		array $query = [],
		array $headers = [],
		float $timeout = 30.0,
	): array {
		try {
			$response = $http->request(
				strtoupper($method),
				$this->url($path),
				$this->options($token, null, $query, $headers, $timeout),
			);
			$status = $response->getStatusCode();
		} catch (HttpExceptionInterface $exception) {
			$response = $exception->getResponse();
			$status = (int) $response->getInfo('http_code');
		}

		return [
			'status' => $status,
			'headers' => $response->getHeaders(false),
			'body' => $response->getContent(false),
		];
	}

	private function url(string $path): string
	{
		$parts = parse_url($path);
		if (!is_array($parts)
			|| !str_starts_with($path, '/api/')
			|| isset($parts['scheme'])
			|| isset($parts['host'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
			|| in_array('..', explode('/', (string) ($parts['path'] ?? '')), true)) {
			throw new \InvalidArgumentException('Business API paths must be relative /api routes.');
		}

		return rtrim($this->baseUrl, '/').$path;
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string|int|float|bool|list<string|int>|null> $query
	 * @param array<string,string> $extraHeaders
	 * @return array<string,mixed>
	 */
	private function options(?string $token, ?array $body, array $query, array $extraHeaders, float $timeout): array
	{
		if (!is_finite($timeout) || $timeout < 0.1 || $timeout > 300.0) {
			throw new \InvalidArgumentException('Business API timeout must be between 0.1 and 300 seconds.');
		}
		$headers = ['Accept' => 'application/json'];
		if (null !== $token && '' !== $token) {
			if (strlen($token) > 4096 || str_contains($token, "\r") || str_contains($token, "\n")) {
				throw new \InvalidArgumentException('Invalid user API credential.');
			}
			$headers['X-AUTH-TOKEN'] = $token;
		}
		foreach ($extraHeaders as $name => $value) {
			if (!in_array(strtolower($name), ['x-site-host', 'x-site-scheme', 'x-schedule-trigger', 'x-batch-trigger', 'x-asset-process-trigger', 'x-background-queue-trigger', 'cookie'], true)
				|| strlen($value) > 8192
				|| str_contains($value, "\r")
				|| str_contains($value, "\n")) {
				throw new \InvalidArgumentException('Unsupported Business API request header.');
			}
			$headers[$name] = $value;
		}

		$options = [
			'headers' => $headers,
			'max_redirects' => 0,
			'timeout' => $timeout,
			'max_duration' => $timeout,
		];
		if ([] !== $query) {
			$options['query'] = $query;
		}
		if (null !== $body) {
			$options['json'] = $body;
		}

		return $options;
	}

	/** @return array<mixed> */
	private function decode(ResponseInterface $response): array
	{
		try {
			$status = $response->getStatusCode();
		} catch (HttpExceptionInterface $exception) {
			throw $this->translateHttpException($exception);
		}
		if ($status < 200 || $status >= 300) {
			throw new BusinessApiException($status);
		}

		$data = $response->toArray(false);
		return $data;
	}

	private function translateHttpException(HttpExceptionInterface $exception): BusinessApiException
	{
		return new BusinessApiException((int) $exception->getResponse()->getInfo('http_code'));
	}

	private function assertBaseUrl(string $baseUrl): void
	{
		$parts = parse_url(trim($baseUrl));
		if (!is_array($parts)
			|| !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
			|| empty($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
			|| isset($parts['path']) && '' !== trim($parts['path'], '/')) {
			throw new \InvalidArgumentException('INTERNAL_API_BASE_URL must be an HTTP(S) origin without credentials, path, query, or fragment.');
		}
		if ('http' === strtolower((string) $parts['scheme']) && 'prod' === $this->environment) {
			throw new \InvalidArgumentException('INTERNAL_API_BASE_URL must use HTTPS in production.');
		}
	}
}
