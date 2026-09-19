<?php

namespace iikiti\CMS\Tests\Search\Service;

use iikiti\CMS\Search\Service\SearchHit;
use iikiti\CMS\Search\Service\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
	public function testDefaults(): void
	{
		$result = new SearchResult();

		self::assertSame([], $result->getHits());
		self::assertSame(0, $result->total);
		self::assertSame(20, $result->limit);
		self::assertSame(0, $result->offset);
		self::assertSame([], $result->facets);
		self::assertSame(0.0, $result->elapsedMs);
	}

	public function testWithHits(): void
	{
		$hit1 = new SearchHit(1, 'Page', 0.95, ['title' => 'Hello']);
		$hit2 = new SearchHit(2, 'User', 0.75, ['title' => 'World']);

		$result = new SearchResult(
			hits: [$hit1, $hit2],
			total: 2,
			limit: 10,
			offset: 0,
		);

		self::assertCount(2, $result->getHits());
		self::assertSame($hit1, $result->getHits()[0]);
		self::assertSame($hit2, $result->getHits()[1]);
		self::assertSame(2, $result->total);
	}

	public function testSearchHitProperties(): void
	{
		$hit = new SearchHit(42, 'Page', 0.85, ['title' => 'Test'], ['title' => '<em>Test</em>']);

		self::assertSame(42, $hit->id);
		self::assertSame('Page', $hit->type);
		self::assertSame(0.85, $hit->rank);
		self::assertSame(['title' => 'Test'], $hit->data);
		self::assertSame(['title' => '<em>Test</em>'], $hit->highlights);
	}

	public function testEmptyHits(): void
	{
		$result = new SearchResult();

		self::assertCount(0, $result->getHits());
	}
}
