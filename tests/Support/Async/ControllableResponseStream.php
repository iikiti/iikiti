<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Generator-backed ResponseStreamInterface for ControllableHttpClient. */
final class ControllableResponseStream implements ResponseStreamInterface
{
	/** @param \Generator<ResponseInterface,ChunkInterface> $generator */
	public function __construct(private readonly \Generator $generator)
	{
	}

	public function key(): ResponseInterface
	{
		return $this->generator->key();
	}

	public function current(): ChunkInterface
	{
		return $this->generator->current();
	}

	public function next(): void
	{
		$this->generator->next();
	}

	public function valid(): bool
	{
		return $this->generator->valid();
	}

	public function rewind(): void
	{
		$this->generator->rewind();
	}
}
