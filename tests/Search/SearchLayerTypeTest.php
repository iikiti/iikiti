<?php

namespace iikiti\CMS\Tests\Search;

use iikiti\CMS\Search\Enum\SearchLayerType;
use PHPUnit\Framework\TestCase;

final class SearchLayerTypeTest extends TestCase
{
	public function testLabels(): void
	{
		self::assertSame('Tokenizer', SearchLayerType::Tokenizer->getLabel());
		self::assertSame('Character Filter', SearchLayerType::CharFilter->getLabel());
		self::assertSame('Token Filter', SearchLayerType::TokenFilter->getLabel());
		self::assertSame('N-gram', SearchLayerType::Ngram->getLabel());
		self::assertSame('Edge N-gram', SearchLayerType::EdgeNgram->getLabel());
		self::assertSame('Lowercase', SearchLayerType::Lowercase->getLabel());
		self::assertSame('Uppercase', SearchLayerType::Uppercase->getLabel());
		self::assertSame('Stop Words', SearchLayerType::Stop->getLabel());
		self::assertSame('Stemmer', SearchLayerType::Stemmer->getLabel());
		self::assertSame('Synonym', SearchLayerType::Synonym->getLabel());
		self::assertSame('Normalizer', SearchLayerType::Normalizer->getLabel());
	}

	public function testPreTokenizerLayers(): void
	{
		self::assertTrue(SearchLayerType::CharFilter->isPreTokenizer());
		self::assertTrue(SearchLayerType::Normalizer->isPreTokenizer());
		self::assertFalse(SearchLayerType::Tokenizer->isPreTokenizer());
		self::assertFalse(SearchLayerType::Lowercase->isPreTokenizer());
	}

	public function testQueryTimeLayers(): void
	{
		self::assertTrue(SearchLayerType::Lowercase->isQueryTime());
		self::assertTrue(SearchLayerType::Uppercase->isQueryTime());
		self::assertTrue(SearchLayerType::Stop->isQueryTime());
		self::assertFalse(SearchLayerType::Stemmer->isQueryTime());
	}

	public function testOptionKeys(): void
	{
		self::assertSame(['min_gram', 'max_gram'], SearchLayerType::Ngram->getOptionKeys());
		self::assertSame(['min_gram', 'max_gram'], SearchLayerType::EdgeNgram->getOptionKeys());
		self::assertSame(['words', 'ignore_case'], SearchLayerType::Stop->getOptionKeys());
		self::assertSame(['algorithm'], SearchLayerType::Stemmer->getOptionKeys());
		self::assertSame(['synonyms', 'ignore_case'], SearchLayerType::Synonym->getOptionKeys());
		self::assertSame(['pattern', 'replacement'], SearchLayerType::CharFilter->getOptionKeys());
		self::assertSame(['lowercase', 'trim'], SearchLayerType::Normalizer->getOptionKeys());
		self::assertSame([], SearchLayerType::Tokenizer->getOptionKeys());
		self::assertSame([], SearchLayerType::TokenFilter->getOptionKeys());
	}
}
