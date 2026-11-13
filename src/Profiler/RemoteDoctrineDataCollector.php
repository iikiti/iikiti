<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/** Stores bounded Doctrine query records returned by trusted page-render API calls. */
final class RemoteDoctrineDataCollector extends DataCollector
{
	private const MAX_QUERIES = 500;
	private const MAX_BYTES = 1_048_576;
	private const MAX_RECORD_BYTES = 16_384;

	public function getName(): string
	{
		return 'iikiti.remote_doctrine';
	}

	/** @param array<mixed>|null $diagnostics */
	public function record(string $path, ?array $diagnostics): void
	{
		$data = $this->currentData();
		++$data['requests'];
		if (!is_array($diagnostics) ||
			!is_array($diagnostics['queries'] ?? null) ||
			!is_int($diagnostics['omitted'] ?? null) ||
			$diagnostics['omitted'] < 0) {
			++$data['unavailable'];
			$this->data = $data;

			return;
		}

		$data['omitted'] += $diagnostics['omitted'];
		$context = is_string($diagnostics['context'] ?? null) && '' !== trim($diagnostics['context']) ?
			str_replace('*/', '* /', $diagnostics['context']) :
			substr($path, 0, 256);
		foreach ($diagnostics['queries'] as $query) {
			$normalized = $this->normalizeQuery($query);
			if (null === $normalized) {
				++$data['omitted'];
				continue;
			}
			$normalized['path'] = substr($path, 0, 256);
			$normalized['context'] = $context;
			try {
				$recordBytes = strlen(json_encode($normalized, JSON_THROW_ON_ERROR));
			} catch (\JsonException) {
				++$data['omitted'];
				continue;
			}
			if ($recordBytes > self::MAX_RECORD_BYTES) {
				++$data['omitted'];
				continue;
			}
			if (count($data['queries']) >= self::MAX_QUERIES || $data['bytes'] + $recordBytes > self::MAX_BYTES) {
				++$data['omitted'];
				continue;
			}

			$data['bytes'] += $recordBytes;
			$data['queries'][] = $normalized;
		}
		$this->data = $data;
	}

	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		$this->data = $this->currentData();
	}

	/** @return list<array{path:string,context:string,connection:string,sql:string,params:array<mixed>,types:array<mixed>,execution_ms:float}> */
	public function getQueries(): array
	{
		return $this->currentData()['queries'];
	}

	public function getRequestCount(): int
	{
		return (int) $this->currentData()['requests'];
	}

	public function getOmittedCount(): int
	{
		return (int) $this->currentData()['omitted'];
	}

	public function getUnavailableCount(): int
	{
		return (int) $this->currentData()['unavailable'];
	}

	public function reset(): void
	{
		$this->data = $this->emptyData();
	}

	/** @return array{requests:int,unavailable:int,omitted:int,bytes:int,queries:list<array{path:string,context:string,connection:string,sql:string,params:array<mixed>,types:array<mixed>,execution_ms:float}>} */
	private function currentData(): array
	{
		$data = is_array($this->data) ? $this->data : [];

		return array_replace($this->emptyData(), $data);
	}

	/** @return array{requests:int,unavailable:int,omitted:int,bytes:int,queries:list<array{path:string,context:string,connection:string,sql:string,params:array<mixed>,types:array<mixed>,execution_ms:float}>} */
	private function emptyData(): array
	{
		return ['requests' => 0, 'unavailable' => 0, 'omitted' => 0, 'bytes' => 0, 'queries' => []];
	}

	/** @return array{connection:string,sql:string,params:array<mixed>,types:array<mixed>,execution_ms:float}|null */
	private function normalizeQuery(mixed $query): ?array
	{
		if (!is_array($query) ||
			!is_string($query['connection'] ?? null) ||
			!is_string($query['sql'] ?? null) ||
			strlen($query['sql']) > 8192 ||
			!is_array($query['params'] ?? null) ||
			!is_array($query['types'] ?? null) ||
			!is_numeric($query['execution_ms'] ?? null)) {
			return null;
		}

		return [
			'connection' => substr($query['connection'], 0, 128),
			'sql' => $query['sql'],
			'params' => $query['params'],
			'types' => $query['types'],
			'execution_ms' => round(max(0.0, (float) $query['execution_ms']), 3),
		];
	}
}
