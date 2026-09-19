<?php

namespace iikiti\CMS\Search\Enum;

/**
 * Analyzer layer types, inspired by the Apache Lucene / Elasticsearch
 * analyzer chain model. Each layer is a step in the text-analysis pipeline
 * that transforms tokens before they are indexed or queried.
 */
enum SearchLayerType: string
{
	case Tokenizer = 'tokenizer';
	case CharFilter = 'charfilter';
	case TokenFilter = 'tokenfilter';
	case Ngram = 'ngram';
	case EdgeNgram = 'edgengram';
	case Lowercase = 'lowercase';
	case Uppercase = 'uppercase';
	case Stop = 'stop';
	case Stemmer = 'stemmer';
	case Synonym = 'synonym';
	case Normalizer = 'normalizer';

	public function getLabel(): string
	{
		return match ($this) {
			self::Tokenizer => 'Tokenizer',
			self::CharFilter => 'Character Filter',
			self::TokenFilter => 'Token Filter',
			self::Ngram => 'N-gram',
			self::EdgeNgram => 'Edge N-gram',
			self::Lowercase => 'Lowercase',
			self::Uppercase => 'Uppercase',
			self::Stop => 'Stop Words',
			self::Stemmer => 'Stemmer',
			self::Synonym => 'Synonym',
			self::Normalizer => 'Normalizer',
		};
	}

	/**
	 * Whether this layer operates before tokenization (character-level).
	 */
	public function isPreTokenizer(): bool
	{
		return in_array($this, [self::CharFilter, self::Normalizer], true);
	}

	/**
	 * Whether this layer operates at query time rather than index time.
	 */
	public function isQueryTime(): bool
	{
		return in_array($this, [self::Lowercase, self::Uppercase, self::Stop], true);
	}

	/**
	 * Returns the expected keys for the layer's JSON options, for validation.
	 *
	 * @return list<string>
	 */
	public function getOptionKeys(): array
	{
		return match ($this) {
			self::Ngram, self::EdgeNgram => ['min_gram', 'max_gram'],
			self::Stop => ['words', 'ignore_case'],
			self::Stemmer => ['algorithm'],
			self::Synonym => ['synonyms', 'ignore_case'],
			self::CharFilter => ['pattern', 'replacement'],
			self::Normalizer => ['lowercase', 'trim'],
			default => [],
		};
	}
}
