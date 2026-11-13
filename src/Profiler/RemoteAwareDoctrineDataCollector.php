<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Merges internal page-render SQL into Doctrine's native query data and metrics. */
final class RemoteAwareDoctrineDataCollector extends DoctrineDataCollector
{
	public function __construct(
		ManagerRegistry $registry,
		private readonly RemoteDoctrineDataCollector $remoteQueries,
		bool $shouldValidateSchema = true,
		?DebugDataHolder $debugDataHolder = null,
		array $secondLevelCacheManagers = [],
	) {
		parent::__construct($registry, $shouldValidateSchema, $debugDataHolder, $secondLevelCacheManagers);
	}

	public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
	{
		parent::collect($request, $response, $exception);
		$this->appendRemoteQueries();
	}

	private function appendRemoteQueries(): void
	{
		$remoteQueries = $this->remoteQueries->getQueries();
		if ([] === $remoteQueries) {
			return;
		}

		$queries = $this->data['queries'];
		$queries['default'] ??= [];
		foreach ($remoteQueries as $remoteQuery) {
			$queries['default'][] = [
				'sql' => sprintf("/* %s | API: %s */\n%s", $remoteQuery['context'], $remoteQuery['path'], $remoteQuery['sql']),
				'params' => $this->cloneVar($remoteQuery['params']),
				'types' => $remoteQuery['types'],
				'executionMS' => $remoteQuery['execution_ms'] / 1000,
				'explainable' => false,
				'runnable' => false,
			];
		}

		$this->data['queries'] = $queries;
	}
}
