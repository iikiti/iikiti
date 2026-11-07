<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\ResponseFragmentFormat;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/** Profiler data for Fiber HTTP requests, API calls and composed controller responses. */
final class AsyncResponseDataCollector extends DataCollector
{
	private const SENSITIVE_QUERY_COMPONENTS = ['token', 'auth', 'authorization', 'authentication', 'password', 'passwd', 'secret', 'signature', 'sig', 'key', 'credential', 'credentials', 'csrf', 'session'];

	public function getName(): string
	{
		return 'iikiti.async_response';
	}

	public function recordHttpRequest(): void
	{
		$data = $this->currentData();
		$data['http_requests'] = ($data['http_requests'] ?? 0) + 1;
		$this->data = $data;
	}

	/** @param array<array-key,mixed> $query */
	public function recordAsyncRequest(string $method, string $url, ?int $status, float $durationMs, bool $failed, array $query = []): void
	{
		$data = $this->currentData();
		$data['async_requests'][] = $this->requestRecord($method, $url, $status, $durationMs, $failed, $query);
		$this->data = $data;
	}

	/** @param array<array-key,mixed> $query */
	public function recordApiSubrequest(string $method, string $url, ?int $status, float $durationMs, bool $failed, array $query = []): void
	{
		$data = $this->currentData();
		$data['api_subrequests'][] = $this->requestRecord($method, $url, $status, $durationMs, $failed, $query);
		$this->data = $data;
	}

	public function recordFiberGroup(int $taskCount, float $workTimeMs, float $waitTimeMs, int $failures): void
	{
		$data = $this->currentData();
		$data['fiber_groups'][] = [
			'tasks' => $taskCount,
			'work_ms' => round($workTimeMs, 2),
			'wait_ms' => round($waitTimeMs, 2),
			'failures' => $failures,
		];
		$this->data = $data;
	}

	public function recordComposition(ResponseFragmentFormat|string $format, int $fragmentCount): void
	{
		$data = $this->currentData();
		$data['compositions'][] = [
			'format' => $format instanceof ResponseFragmentFormat ? $format->value : $format,
			'fragments' => $fragmentCount,
		];
		$this->data = $data;
	}

	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		$data = $this->currentData();
		$data['response'] = [
			'mode' => $response instanceof DomHtmlResponse
				? 'DOM HTML document'
				: ($response instanceof JsonResponse ? 'JSON response' : 'standard response body'),
			'content_type' => $response->headers->get('Content-Type') ?? 'unknown',
			'status' => $response->getStatusCode(),
		];
		$data['async_used'] = [] !== ($data['fiber_groups'] ?? []) || (int) ($data['http_requests'] ?? 0) > 0;
		$data['dom_document_reused'] = $response instanceof DomHtmlResponse;
		$data['response_path_used'] = $data['async_used'] || $data['dom_document_reused'] || [] !== ($data['compositions'] ?? []);
		$this->data = $data;
	}

	public function isAsyncUsed(): bool
	{
		return (bool) ($this->currentData()['async_used'] ?? false);
	}

	public function isDomDocumentReused(): bool
	{
		return (bool) ($this->currentData()['dom_document_reused'] ?? false);
	}

	public function isResponsePathUsed(): bool
	{
		return (bool) ($this->currentData()['response_path_used'] ?? false);
	}

	/** @return array{mode:string,content_type:string,status:int} */
	public function getResponse(): array
	{
		$response = $this->currentData()['response'] ?? null;

		return is_array($response) ? $response : ['mode' => 'not collected', 'content_type' => 'unknown', 'status' => 0];
	}

	/** @return list<array{tasks:int,work_ms:float,wait_ms:float,failures:int}> */
	public function getFiberGroups(): array
	{
		$groups = $this->currentData()['fiber_groups'] ?? [];

		return array_values(is_array($groups) ? $groups : []);
	}

	public function getFiberGroupCount(): int
	{
		return count($this->getFiberGroups());
	}

	public function getFailureCount(): int
	{
		return array_sum(array_column($this->getFiberGroups(), 'failures'));
	}

	public function getHttpRequestCount(): int
	{
		return (int) ($this->currentData()['http_requests'] ?? 0);
	}

	/** @return list<array{method:string,url:string,status:?int,duration_ms:float,failed:bool}> */
	public function getAsyncRequests(): array
	{
		$requests = $this->currentData()['async_requests'] ?? [];

		return array_values(is_array($requests) ? $requests : []);
	}

	/** @return list<array{method:string,url:string,status:?int,duration_ms:float,failed:bool}> */
	public function getApiSubrequests(): array
	{
		$requests = $this->currentData()['api_subrequests'] ?? [];

		return array_values(is_array($requests) ? $requests : []);
	}

	/** @return list<array{format:string,fragments:int}> */
	public function getCompositions(): array
	{
		$compositions = $this->currentData()['compositions'] ?? [];

		return array_values(is_array($compositions) ? $compositions : []);
	}

	public function getFragmentCount(): int
	{
		return array_sum(array_column($this->getCompositions(), 'fragments'));
	}

	public function getTotalWorkTimeMs(): float
	{
		return array_sum(array_column($this->getFiberGroups(), 'work_ms'));
	}

	public function getTotalWaitTimeMs(): float
	{
		return array_sum(array_column($this->getFiberGroups(), 'wait_ms'));
	}

	/** @return array<string,mixed> */
	private function currentData(): array
	{
		return is_array($this->data) ? $this->data : [];
	}

	/** @param array<array-key,mixed> $query
	 *  @return array{method:string,url:string,status:?int,duration_ms:float,failed:bool}
	 */
	private function requestRecord(string $method, string $url, ?int $status, float $durationMs, bool $failed, array $query): array
	{
		return [
			'method' => strtoupper($method),
			'url' => $this->sanitizeUrl($url, $query),
			'status' => $status,
			'duration_ms' => round(max(0.0, $durationMs), 2),
			'failed' => $failed,
		];
	}

	/** @param array<array-key,mixed> $query */
	private function sanitizeUrl(string $url, array $query): string
	{
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return '[invalid URL]';
		}

		$sanitized = '';
		if (isset($parts['scheme'])) {
			$sanitized .= strtolower((string) $parts['scheme']).'://';
		}
		if (isset($parts['host'])) {
			$sanitized .= (string) $parts['host'];
		}
		if (isset($parts['port'])) {
			$sanitized .= ':'.$parts['port'];
		}

		$path = (string) ($parts['path'] ?? '');
		$path = preg_replace('#(/api/public/(?:assets/|sites/[^/?]+/assets/))[^/?]+(?=/|$)#i', '$1[redacted]', $path) ?? $path;
		$sanitized .= $path;

		$queryString = (string) ($parts['query'] ?? '');
		if ([] !== $query) {
			$additionalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
			if ('' !== $additionalQuery) {
				$queryString .= ('' === $queryString ? '' : '&').$additionalQuery;
			}
		}

		if ('' !== $queryString) {
			$sanitized .= '?'.$this->redactQuery($queryString);
		}

		return $sanitized;
	}

	private function redactQuery(string $query): string
	{
		return implode('&', array_map(static function (string $pair): string {
			[$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
			$components = preg_split('/[^a-zA-Z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', urldecode($key)) ?: [];
			$sensitive = [] !== array_intersect(array_map('strtolower', $components), self::SENSITIVE_QUERY_COMPONENTS);
			if (null === $value || !$sensitive) {
				return $pair;
			}

			return $key.'=%5Bredacted%5D';
		}, explode('&', $query)));
	}
}
