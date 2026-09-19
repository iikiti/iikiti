<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchIndexField;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use PHPUnit\Framework\TestCase;

final class SearchIndexFieldTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$field = new SearchIndexField('title', SearchFieldSourceType::Property, 'title');

		self::assertSame('title', $field->getName());
		self::assertSame(SearchFieldSourceType::Property, $field->getSourceType());
		self::assertSame('title', $field->getSource());
		self::assertSame('text', $field->getDataType());
		self::assertSame(1, $field->getWeight());
		self::assertFalse($field->isFacetable());
		self::assertFalse($field->isSortable());
		self::assertSame(0, $field->getPosition());
	}

	public function testWeightValidation(): void
	{
		$field = new SearchIndexField('title');

		$field->setWeight(1);
		self::assertSame(1, $field->getWeight());

		$field->setWeight(4);
		self::assertSame(4, $field->getWeight());

		$this->expectException(\InvalidArgumentException::class);
		$field->setWeight(5);
	}

	public function testWeightLowerBound(): void
	{
		$field = new SearchIndexField('title');

		$this->expectException(\InvalidArgumentException::class);
		$field->setWeight(0);
	}

	public function testWeightNegative(): void
	{
		$field = new SearchIndexField('title');

		$this->expectException(\InvalidArgumentException::class);
		$field->setWeight(-1);
	}

	public function testSourceTypeConversion(): void
	{
		$field = new SearchIndexField('content');

		$field->setSourceType(SearchFieldSourceType::Virtual);
		self::assertSame(SearchFieldSourceType::Virtual, $field->getSourceType());

		$field->setSourceType(SearchFieldSourceType::Column);
		self::assertSame(SearchFieldSourceType::Column, $field->getSourceType());

		$field->setSourceType(SearchFieldSourceType::Alias);
		self::assertSame(SearchFieldSourceType::Alias, $field->getSourceType());
	}

	public function testSetSearchIndex(): void
	{
		$index = new SearchIndex('test', 'Test');
		$field = new SearchIndexField('title');

		$field->setSearchIndex($index);

		self::assertSame($index, $field->getSearchIndex());
	}

	public function testGetLanguageDefaultsToNull(): void
	{
		$field = new SearchIndexField('title');

		self::assertNull($field->getLanguage());
	}

	public function testSetLanguage(): void
	{
		$field = new SearchIndexField('title');
		$field->setLanguage('french');

		self::assertSame('french', $field->getLanguage());
	}

	public function testSetAnalyzerName(): void
	{
		$field = new SearchIndexField('title');
		$field->setAnalyzerName('my_analyzer');

		self::assertSame('my_analyzer', $field->getAnalyzerName());
	}
}
