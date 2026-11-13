<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Profiler;

use iikiti\CMS\Profiler\RemoteDoctrineDataCollector;
use PHPUnit\Framework\TestCase;

final class RemoteDoctrineDataCollectorTest extends TestCase
{
	public function testAggregatesQueriesAndReportsUnavailableDiagnostics(): void
	{
		$collector = new RemoteDoctrineDataCollector();
		$collector->record('/api/public/render/home/manifest', [
			'queries' => [$this->query('SELECT 1')],
			'omitted' => 2,
		]);
		$collector->record('/api/public/render/home/fragments/region', null);

		self::assertSame(2, $collector->getRequestCount());
		self::assertSame(1, count($collector->getQueries()));
		self::assertSame('/api/public/render/home/manifest', $collector->getQueries()[0]['path']);
		self::assertSame('/api/public/render/home/manifest', $collector->getQueries()[0]['context']);
		self::assertSame(2, $collector->getOmittedCount());
		self::assertSame(1, $collector->getUnavailableCount());

		$collector->reset();
		self::assertSame(0, $collector->getRequestCount());
		self::assertSame([], $collector->getQueries());
	}

	public function testMalformedDiagnosticsAreCountedAndDropped(): void
	{
		$collector = new RemoteDoctrineDataCollector();
		$collector->record('/api/public/render/home/manifest', ['queries' => ['invalid'], 'omitted' => 0]);

		self::assertSame([], $collector->getQueries());
		self::assertSame(1, $collector->getOmittedCount());
	}

	public function testAggregationHasARequestWideLimit(): void
	{
		$collector = new RemoteDoctrineDataCollector();
		$collector->record('/api/public/render/home/manifest', [
			'queries' => array_fill(0, 501, $this->query('SELECT 1')),
			'omitted' => 0,
		]);

		self::assertCount(500, $collector->getQueries());
		self::assertSame(1, $collector->getOmittedCount());
	}

	/** @return array{connection:string,sql:string,params:array<int,string>,types:array<int,string>,execution_ms:float} */
	private function query(string $sql): array
	{
		return [
			'connection' => 'default',
			'sql' => $sql,
			'params' => [],
			'types' => [],
			'execution_ms' => 0.25,
		];
	}
}
