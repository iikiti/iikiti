<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SearchAnalyzerLayer;
use iikiti\CMS\Search\Enum\SearchLayerType;
use PHPUnit\Framework\TestCase;

final class SearchAnalyzerLayerTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$layer = new SearchAnalyzerLayer('standard');

		self::assertSame('standard', $layer->getName());
		self::assertSame(SearchLayerType::TokenFilter, $layer->getType());
		self::assertSame(0, $layer->getPosition());
		self::assertSame([], $layer->getOptions());
	}

	public function testTypeConversion(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer', SearchLayerType::Stemmer);

		self::assertSame(SearchLayerType::Stemmer, $layer->getType());

		$layer->setType(SearchLayerType::Ngram);
		self::assertSame(SearchLayerType::Ngram, $layer->getType());
	}

	public function testOptionsWithValidKeys(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer', SearchLayerType::Ngram);

		$layer->setOptions(['min_gram' => 2, 'max_gram' => 10]);

		self::assertSame(['min_gram' => 2, 'max_gram' => 10], $layer->getOptions());
	}

	public function testOptionsValidationRejectsUnknownKey(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer', SearchLayerType::Stemmer);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Option "unknown_key" is not valid for layer type');

		$layer->setOptions(['unknown_key' => 'value']);
	}

	public function testOptionsValidationPassesForLayerWithoutExpectedKeys(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer', SearchLayerType::Tokenizer);
		$layer->setOptions(['any_key' => 'any_value']);

		self::assertSame(['any_key' => 'any_value'], $layer->getOptions());
	}

	public function testOptionGettersAndSetters(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer', SearchLayerType::Stop);

		$layer->setOptions(['words' => ['the', 'a'], 'ignore_case' => true]);

		self::assertSame(['the', 'a'], $layer->getOption('words'));
		self::assertTrue($layer->getOption('ignore_case'));
		self::assertNull($layer->getOption('nonexistent'));
		self::assertSame('default', $layer->getOption('nonexistent', 'default'));
	}

	public function testPosition(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer');
		$layer->setPosition(3);

		self::assertSame(3, $layer->getPosition());
	}

	public function testIndexAndFieldIdSetters(): void
	{
		$layer = new SearchAnalyzerLayer('my_analyzer');

		$layer->setSearchIndexId(1);
		self::assertSame(1, $layer->getSearchIndexId());

		$layer->setFieldId(42);
		self::assertSame(42, $layer->getFieldId());
	}
}
