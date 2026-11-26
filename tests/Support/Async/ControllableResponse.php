<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Controllable lazy ResponseInterface supporting causal hold gates without sleeps. */
final class ControllableResponse implements ResponseInterface
{
	/** @var callable():bool|null */
	private $releasePredicate = null;
	/** @var callable(self):void|null */
	private $onCancel = null;
	/** @var callable(self):void|null */
	private $onComplete = null;
	/** @var callable():void|null */
	private $onFinish = null;
	private bool $canceled = false;
	private bool $completed = false;
	private bool $emittedFirst = false;
	private int $chunkCursor = 0;
	private int $offset = 0;
	private bool $primeTimeout = true;
	private string $method = 'GET';
	private string $url = '';
	private mixed $userData = null;
	private ?string $errorMessage = null;
	private string $key = '';
	/** @var list<string> */
	private array $chunks;
	/** @var array<string,list<string>> */
	private array $normalizedHeaders;

	/**
	 * @param list<string> $chunks
	 * @param array<string,string|list<string>> $headers
	 */
	private function __construct(
		array $chunks,
		private readonly int $statusCode = 200,
		array $headers = [],
		private readonly ?\Throwable $failure = null,
		private readonly bool $forceTimeout = false,
	) {
		$this->chunks = array_values(array_filter($chunks, static fn (string $c): bool => '' !== $c));
		$this->normalizedHeaders = [];
		foreach ($headers as $name => $values) {
			$lower = strtolower($name);
			$this->normalizedHeaders[$lower] = is_array($values) ? $values : [$values];
		}
	}

	/** @param array<string,string|list<string>> $headers */
	public static function ok(string $body = '', int $status = 200, array $headers = [], ?string $key = null): self
	{
		$response = new self('' === $body ? [] : [$body], $status, $headers);
		if (null !== $key) {
			$response->key = $key;
		}

		return $response;
	}

	/**
	 * @param list<string> $chunks
	 * @param array<string,string|list<string>> $headers
	 */
	public static function chunks(array $chunks, int $status = 200, array $headers = [], ?string $key = null): self
	{
		$response = new self($chunks, $status, $headers);
		if (null !== $key) {
			$response->key = $key;
		}

		return $response;
	}

	/** @param array<string,string|list<string>> $headers */
	public static function error(int $status, string $body = '', array $headers = [], ?string $key = null): self
	{
		return self::ok($body, $status, $headers, $key);
	}

	public static function transportError(\Throwable $exception, ?string $key = null): self
	{
		$response = new self([], 0, [], $exception);
		if (null !== $key) {
			$response->key = $key;
		}

		return $response;
	}

	public static function timeout(?string $key = null): self
	{
		$response = new self([], 0, [], new TimeoutException('Request timed out.'), true);
		if (null !== $key) {
			$response->key = $key;
		}

		return $response;
	}

	/** @param callable():bool $predicate */
	public function holdUntil(callable $predicate): self
	{
		$this->releasePredicate = $predicate;

		return $this;
	}

	public function primeTimeout(bool $enabled = true): self
	{
		$this->primeTimeout = $enabled;

		return $this;
	}

	/** @param callable(self):void $callback */
	public function onCancel(callable $callback): self
	{
		$this->onCancel = $callback;

		return $this;
	}

	/** @param callable(self):void $callback */
	public function onComplete(callable $callback): self
	{
		$this->onComplete = $callback;

		return $this;
	}

	public function withKey(string $key): self
	{
		$this->key = $key;

		return $this;
	}

	/** @internal */
	public function bindRequest(string $method, string $url, mixed $userData, callable $onFinish): void
	{
		$this->method = $method;
		$this->url = $url;
		$this->userData = $userData;
		$this->onFinish = $onFinish;
		if ('' === $this->key) {
			$this->key = $url;
		}
	}

	public function key(): string
	{
		return $this->key;
	}

	public function isCanceled(): bool
	{
		return $this->canceled;
	}

	public function isCompleted(): bool
	{
		return $this->completed;
	}

	public function isForceTimeout(): bool
	{
		return $this->forceTimeout;
	}

	public function shouldPrimeTimeout(): bool
	{
		return $this->primeTimeout;
	}

	public function canRelease(): bool
	{
		if ($this->canceled || $this->completed) {
			return false;
		}
		if (null === $this->releasePredicate) {
			return true;
		}

		return (bool) ($this->releasePredicate)();
	}

	/** @internal */
	public function nextChunk(): ?ControllableChunk
	{
		if ($this->canceled || $this->completed) {
			return null;
		}

		if (null !== $this->failure) {
			$this->errorMessage = $this->failure->getMessage();
			$this->finish();

			return ControllableChunk::error($this->failure, $this->offset);
		}

		if (!$this->emittedFirst) {
			$this->emittedFirst = true;

			return ControllableChunk::first();
		}

		if ($this->chunkCursor < count($this->chunks)) {
			$piece = $this->chunks[$this->chunkCursor++];
			$chunk = ControllableChunk::data($piece, $this->offset);
			$this->offset += strlen($piece);

			return $chunk;
		}

		$this->finish();

		return ControllableChunk::last($this->offset);
	}

	public function getStatusCode(): int
	{
		$this->driveSynchronouslyIfNeeded();

		return $this->statusCode;
	}

	public function getHeaders(bool $throw = true): array
	{
		$this->driveSynchronouslyIfNeeded();
		if ($throw) {
			$this->checkStatusCode();
		}

		return $this->normalizedHeaders;
	}

	public function getContent(bool $throw = true): string
	{
		$this->driveSynchronouslyIfNeeded();
		if ($throw) {
			$this->checkStatusCode();
		}

		return implode('', $this->chunks);
	}

	/** @return array<mixed> */
	public function toArray(bool $throw = true): array
	{
		$content = $this->getContent($throw);
		if ('' === $content) {
			throw new JsonException('Response body is empty.');
		}
		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			throw new JsonException(sprintf('Expected JSON array for "%s".', $this->url));
		}

		return $decoded;
	}

	public function cancel(): void
	{
		if ($this->canceled || $this->completed) {
			return;
		}
		$this->canceled = true;
		$this->errorMessage = 'Response canceled.';
		if (null !== $this->onCancel) {
			($this->onCancel)($this);
		}
		$this->finish();
	}

	public function getInfo(?string $type = null): mixed
	{
		$rawHeaders = [];
		foreach ($this->normalizedHeaders as $name => $values) {
			foreach ($values as $value) {
				$rawHeaders[] = $name.': '.$value;
			}
		}
		$info = [
			'canceled' => $this->canceled,
			'error' => $this->errorMessage,
			'http_code' => $this->emittedFirst || $this->completed ? $this->statusCode : 0,
			'http_method' => $this->method,
			'redirect_count' => 0,
			'redirect_url' => null,
			'response_headers' => $rawHeaders,
			'start_time' => 0.0,
			'url' => $this->url,
			'user_data' => $this->userData,
			'trailers' => $this->completed ? [] : null,
		];

		return null === $type ? $info : ($info[$type] ?? null);
	}

	private function driveSynchronouslyIfNeeded(): void
	{
		if ($this->canceled) {
			throw new TransportException($this->errorMessage ?? 'Response canceled.');
		}
		if (null !== $this->failure) {
			$this->errorMessage = $this->failure->getMessage();
			$this->finish();
			throw $this->failure;
		}
		if (!$this->emittedFirst) {
			if (!$this->canRelease()) {
				throw new \LogicException(sprintf('Response "%s" is blocked by a hold gate.', $this->key));
			}
			$this->emittedFirst = true;
		}
		if (!$this->completed && $this->chunkCursor >= count($this->chunks)) {
			$this->finish();
		}
	}

	private function checkStatusCode(): void
	{
		if ($this->statusCode >= 500) {
			throw new ServerException($this);
		}
		if ($this->statusCode >= 400) {
			throw new ClientException($this);
		}
		if ($this->statusCode >= 300) {
			throw new RedirectionException($this);
		}
	}

	private function finish(): void
	{
		if ($this->completed) {
			return;
		}
		$this->completed = true;
		if (!$this->canceled && null !== $this->onComplete) {
			($this->onComplete)($this);
		}
		if (null !== $this->onFinish) {
			($this->onFinish)();
		}
	}
}
