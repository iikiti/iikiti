<?php

namespace iikiti\CMS\Tests\Search\Service;

use iikiti\CMS\Search\Service\SearchParams;
use PHPUnit\Framework\TestCase;

final class SearchParamsTest extends TestCase
{
	public function testDefaults(): void
	{
		$params = new SearchParams();

		self::assertSame(20, $params->limit);
		self::assertSame(0, $params->offset);
		self::assertNull($params->language);
		self::assertNull($params->queryType);
		self::assertTrue($params->highlight);
		self::assertSame([], $params->filters);
		self::assertSame([], $params->extraCriteria);
		self::assertNull($params->sort);
		self::assertSame('desc', $params->direction);
		self::assertNull($params->analyzer);
		self::assertNull($params->minWordLength);
	}

	public function testFromOptions(): void
	{
		$params = SearchParams::fromOptions([
			'limit' => 50,
			'offset' => 10,
			'language' => 'french',
			'queryType' => 'websearch',
			'highlight' => false,
			'filters' => ['category' => 'news'],
			'criteria' => ['status' => 'published'],
			'sort' => 'created_date',
			'direction' => 'asc',
			'analyzer' => 'standard',
			'minWordLength' => 3,
		]);

		self::assertSame(50, $params->limit);
		self::assertSame(10, $params->offset);
		self::assertSame('french', $params->language);
		self::assertSame('websearch', $params->queryType);
		self::assertFalse($params->highlight);
		self::assertSame(['category' => 'news'], $params->filters);
		self::assertSame(['status' => 'published'], $params->extraCriteria);
		self::assertSame('created_date', $params->sort);
		self::assertSame('asc', $params->direction);
		self::assertSame('standard', $params->analyzer);
		self::assertSame(3, $params->minWordLength);
	}

	public function testFromOptionsWithEmptyArray(): void
	{
		$params = SearchParams::fromOptions([]);

		self::assertSame(20, $params->limit);
		self::assertSame(0, $params->offset);
		self::assertTrue($params->highlight);
		self::assertSame([], $params->filters);
		self::assertSame([], $params->extraCriteria);
	}

	public function testFromOptionsWithInvalidTypes(): void
	{
		$params = SearchParams::fromOptions([
			'limit' => '100',
			'offset' => '20',
			'highlight' => '0',
		]);

		self::assertSame(100, $params->limit);
		self::assertSame(20, $params->offset);
		self::assertFalse($params->highlight);
	}

	public function testFromOptionsWithFiltersNotArray(): void
	{
		$params = SearchParams::fromOptions([
			'filters' => 'not_an_array',
			'criteria' => 'not_an_array',
		]);

		self::assertSame([], $params->filters);
		self::assertSame([], $params->extraCriteria);
	}
}
