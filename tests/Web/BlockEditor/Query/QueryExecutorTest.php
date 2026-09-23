<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Query;

use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QuerySourceInterface;
use PHPUnit\Framework\TestCase;

final class QueryExecutorTest extends TestCase
{
	public function testExecutesKnownSourceAndPassesSiteScope(): void
	{
		$source = new class implements QuerySourceInterface {
			public bool $called = false;
			public ?int $siteId = null;
			public ?QueryDefinition $received = null;

			public function getName(): string
			{
				return 'objects';
			}

			public function execute(QueryDefinition $definition, ?int $siteId): array
			{
				$this->called = true;
				$this->siteId = $siteId;
				$this->received = $definition;

				return [['id' => 1], ['id' => 2]];
			}
		};

		$executor = new QueryExecutor([$source]);
		$definition = QueryDefinition::fromArray([
			'source' => 'objects',
			'limit' => 5,
		]);

		$results = $executor->execute($definition, 42);

		$this->assertTrue($source->called);
		$this->assertSame(42, $source->siteId);
		$this->assertSame(5, $source->received?->getLimit());
		$this->assertSame([['id' => 1], ['id' => 2]], $results);
	}

	public function testReturnsEmptyWhenSourceUnknown(): void
	{
		$source = new class implements QuerySourceInterface {
			public function getName(): string
			{
				return 'objects';
			}

			public function execute(QueryDefinition $definition, ?int $siteId): array
			{
				return [];
			}
		};

		$executor = new QueryExecutor([$source]);

		$this->assertSame([], $executor->execute(
			QueryDefinition::fromArray(['source' => 'unknown']),
			null
		));
	}

	public function testHasSourceReportsRegisteredSources(): void
	{
		$source = new class implements QuerySourceInterface {
			public function getName(): string
			{
				return 'objects';
			}

			public function execute(QueryDefinition $definition, ?int $siteId): array
			{
				return [];
			}
		};
		$executor = new QueryExecutor([$source]);

		$this->assertTrue($executor->hasSource('objects'));
		$this->assertFalse($executor->hasSource('other'));
	}
}
