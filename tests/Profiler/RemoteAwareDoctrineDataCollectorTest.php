<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Profiler;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Profiler\RemoteAwareDoctrineDataCollector;
use iikiti\CMS\Profiler\RemoteDoctrineDataCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RemoteAwareDoctrineDataCollectorTest extends TestCase
{
	public function testRemoteQueriesAppearInTheNativeDoctrineMetricsAndQueryList(): void
	{
		$remoteQueries = new RemoteDoctrineDataCollector();
		$remoteQueries->record('/api/public/render/home/manifest', [
			'context' => 'Page "/" | Template "Landing" | Twig "@Theme/layout.twig"',
			'queries' => [[
				'connection' => 'default',
				'sql' => 'SELECT * FROM pages WHERE id = ?',
				'params' => [1],
				'types' => [1],
				'execution_ms' => 0.4,
			]],
			'omitted' => 0,
		]);
		$registry = $this->createStub(ManagerRegistry::class);
		$registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
		$registry->method('getManagerNames')->willReturn([]);
		$registry->method('getManagers')->willReturn([]);
		$collector = new RemoteAwareDoctrineDataCollector($registry, $remoteQueries, false, new DebugDataHolder());
		$request = Request::create('/');
		$response = new Response();

		$collector->collect($request, $response);
		$collector->collect($request, $response);
		$queries = $collector->getQueries()['default'];

		self::assertSame(1, $collector->getQueryCount());
		self::assertSame(1, $collector->getGroupedQueryCount());
		self::assertEqualsWithDelta(0.0004, $collector->getTime(), 0.00001);
		self::assertCount(1, $queries);
		self::assertStringContainsString('Template "Landing"', $queries[0]['sql']);
		self::assertStringContainsString('@Theme/layout.twig', $queries[0]['sql']);
		self::assertFalse($queries[0]['explainable']);
		self::assertFalse($queries[0]['runnable']);
	}
}
