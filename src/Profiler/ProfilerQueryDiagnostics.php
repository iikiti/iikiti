<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use Symfony\Component\HttpFoundation\Request;

/** Signs the opt-in transport and bounds API-side Doctrine query snapshots. */
final readonly class ProfilerQueryDiagnostics
{
	public const QUERY_DATA_KEY = '_iikiti_profiler_doctrine';
	public const RENDER_CONTEXT_ATTRIBUTE = '_iikiti_profiler_render_context';
	public const RENDER_CONTEXT_ENABLED_ATTRIBUTE = '_iikiti_profiler_render_context_enabled';
	public const REQUEST_ID_HEADER = 'X-IIKITI-Profiler-Query-Id';
	public const SIGNATURE_HEADER = 'X-IIKITI-Profiler-Query-Signature';

	private const MAX_QUERIES_PER_RESPONSE = 50;
	private const MAX_RESPONSE_BYTES = 65_536;
	private const MAX_SQL_BYTES = 8192;

	public function __construct(private string $applicationSecret)
	{
	}

	/** @return array<string,string> */
	public function requestHeaders(string $method, string $path): array
	{
		$method = strtoupper($method);
		if (!$this->supports($method, $path)) {
			return [];
		}

		$requestId = bin2hex(random_bytes(16));

		return [
			self::REQUEST_ID_HEADER => $requestId,
			self::SIGNATURE_HEADER => hash_hmac('sha256', $this->signaturePayload($method, $path, $requestId), $this->applicationSecret),
		];
	}

	public function isTrustedRequest(Request $request): bool
	{
		$path = $request->getPathInfo();
		$method = strtoupper($request->getMethod());
		$requestId = $request->headers->get(self::REQUEST_ID_HEADER, '');
		$signature = $request->headers->get(self::SIGNATURE_HEADER, '');
		if (!$this->supports($method, $path) ||
			1 !== preg_match('/^[a-f0-9]{32}$/D', $requestId) ||
			1 !== preg_match('/^[a-f0-9]{64}$/D', $signature)) {
			return false;
		}

		return hash_equals(
			hash_hmac('sha256', $this->signaturePayload($method, $path, $requestId), $this->applicationSecret),
			$signature,
		);
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $queriesByConnection
	 *
	 * @return array{queries:list<array{connection:string,sql:string,params:array<mixed>,types:array<mixed>,execution_ms:float}>,omitted:int,context:?string}
	 */
	public function snapshot(array $queriesByConnection, ?string $context = null): array
	{
		$queries = [];
		$omitted = 0;
		$payloadBytes = 0;
		foreach ($queriesByConnection as $connection => $connectionQueries) {
			foreach ($connectionQueries as $query) {
				$sql = $query['sql'] ?? null;
				if (!is_string($sql) || strlen($sql) > self::MAX_SQL_BYTES || count($queries) >= self::MAX_QUERIES_PER_RESPONSE) {
					++$omitted;
					continue;
				}

				$params = $this->normalizeValue(is_array($query['params'] ?? null) ? $query['params'] : []);
				$types = $this->normalizeValue(is_array($query['types'] ?? null) ? $query['types'] : []);
				$duration = $query['executionMS'] ?? null;
				$record = [
					'connection' => substr((string) $connection, 0, 128),
					'sql' => $sql,
					'params' => is_array($params) ? $params : [],
					'types' => is_array($types) ? $types : [],
					'execution_ms' => is_numeric($duration) ? round(max(0.0, (float) $duration * 1000), 3) : 0.0,
				];
				$recordBytes = strlen(json_encode($record, JSON_THROW_ON_ERROR));
				if ($payloadBytes + $recordBytes > self::MAX_RESPONSE_BYTES) {
					++$omitted;
					continue;
				}

				$payloadBytes += $recordBytes;
				$queries[] = $record;
			}
		}

		return [
			'queries' => $queries,
			'omitted' => $omitted,
			'context' => $this->normalizeContext($context),
		];
	}

	private function supports(string $method, string $path): bool
	{
		if ('POST' === $method && '/api/auth/session-token' === $path) {
			return true;
		}

		return 1 === preg_match(
			'~^/api/public/render/(?:home|pages/[\w\d_-]+)/(?:manifest|fragments/(?:region|shell|widget))$|^/api/public/render/providers/[a-z0-9][a-z0-9-]{0,62}\.[a-z][a-z0-9_.-]{0,63}$~D',
			$path,
		);
	}

	private function signaturePayload(string $method, string $path, string $requestId): string
	{
		return $method."\n".$path."\n".$requestId;
	}

	private function normalizeValue(mixed $value, int $depth = 0): mixed
	{
		if ($depth >= 5) {
			return '[nested value omitted]';
		}
		if (is_string($value)) {
			if (1 !== preg_match('//u', $value)) {
				return '[binary data omitted; bytes='.strlen($value).']';
			}
			if (strlen($value) > 4096) {
				return (iconv_substr($value, 0, 1024, 'UTF-8') ?: '').' [truncated]';
			}

			return $value;
		}
		if (null === $value || is_int($value) || is_float($value) || is_bool($value)) {
			return $value;
		}
		if ($value instanceof \BackedEnum) {
			return $value->value;
		}
		if (is_array($value)) {
			$normalized = [];
			foreach (array_slice($value, 0, 100, true) as $key => $item) {
				$normalized[$key] = $this->normalizeValue($item, $depth + 1);
			}

			return $normalized;
		}

		return '['.get_debug_type($value).' omitted]';
	}

	private function normalizeContext(?string $context): ?string
	{
		if (null === $context || '' === trim($context)) {
			return null;
		}

		$context = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $context) ?? '';
		$context = str_replace('*/', '* /', $context);

		return iconv_substr($context, 0, 512, 'UTF-8') ?: null;
	}
}
