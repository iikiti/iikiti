<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ChunkInterface;

/** Controllable ChunkInterface implementation for deterministic async tests. */
final class ControllableChunk implements ChunkInterface
{
	private bool $didThrow = false;

	private function __construct(
		private readonly bool $timeout,
		private readonly bool $first,
		private readonly bool $last,
		private readonly string $content,
		private readonly int $offset,
		private readonly ?\Throwable $error = null,
	) {
	}

	public static function timeout(): self
	{
		return new self(true, false, false, '', 0);
	}

	public static function first(): self
	{
		return new self(false, true, false, '', 0);
	}

	public static function data(string $content, int $offset): self
	{
		return new self(false, false, false, $content, $offset);
	}

	public static function last(int $offset): self
	{
		return new self(false, false, true, '', $offset);
	}

	public static function error(\Throwable $error, int $offset = 0): self
	{
		return new self(false, false, false, '', $offset, $error);
	}

	public function isTimeout(): bool
	{
		if (null !== $this->error) {
			$this->didThrow = true;
			throw $this->toTransportException($this->error);
		}
		if ($this->timeout) {
			$this->didThrow = true;
		}

		return $this->timeout;
	}

	public function isFirst(): bool
	{
		$this->throwIfErroredOrTimedOut();

		return $this->first;
	}

	public function isLast(): bool
	{
		$this->throwIfErroredOrTimedOut();

		return $this->last;
	}

	/** @return array{int,array<string,list<string>>}|null */
	public function getInformationalStatus(): ?array
	{
		$this->throwIfErroredOrTimedOut();

		return null;
	}

	public function getContent(): string
	{
		$this->throwIfErroredOrTimedOut();

		return $this->content;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function getError(): ?string
	{
		if (null !== $this->error) {
			return $this->error->getMessage();
		}

		return $this->timeout ? 'Idle timeout reached.' : null;
	}

	public function __destruct()
	{
		if (!$this->didThrow && (null !== $this->error || $this->timeout)) {
			$this->didThrow = true;
			throw null !== $this->error
				? $this->toTransportException($this->error)
				: new TimeoutException('Idle timeout reached.');
		}
	}

	private function throwIfErroredOrTimedOut(): void
	{
		if (null !== $this->error) {
			$this->didThrow = true;
			throw $this->toTransportException($this->error);
		}
		if ($this->timeout) {
			$this->didThrow = true;
			throw new TimeoutException('Idle timeout reached.');
		}
	}

	private function toTransportException(\Throwable $error): \Throwable
	{
		return $error instanceof TimeoutException || $error instanceof TransportException
			? $error
			: new TransportException($error->getMessage(), 0, $error);
	}
}
