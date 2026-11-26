<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

/** Manages a real loopback TCP barrier HTTP server process for Layer 2 tests. */
final class LocalBarrierHttpServer
{
	/** @var resource|null */
	private $process = null;
	private int $port = 0;

	public function start(): self
	{
		$script = __DIR__.'/barrier_server.php';
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = @proc_open([\PHP_BINARY, $script], $descriptors, $pipes, dirname(__DIR__, 3));
		if (!is_resource($process)) {
			throw new \RuntimeException('Unable to start LocalBarrierHttpServer.');
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$deadline = microtime(true) + 3.0;
		$buffer = '';
		while (microtime(true) < $deadline) {
			$chunk = fread($pipes[1], 1024);
			if (is_string($chunk) && '' !== $chunk) {
				$buffer .= $chunk;
				if (1 === preg_match('/READY (\d+)/', $buffer, $matches)) {
					$this->port = (int) $matches[1];
					$this->process = $process;
					fclose($pipes[1]);
					fclose($pipes[2]);

					return $this;
				}
			}
			$status = proc_get_status($process);
			if (!$status['running']) {
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new \RuntimeException('LocalBarrierHttpServer exited prematurely: '.$stderr);
			}
			usleep(10_000);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_terminate($process);
		proc_close($process);
		throw new \RuntimeException('Timed out waiting for LocalBarrierHttpServer startup.');
	}

	public function baseUrl(): string
	{
		if ($this->port <= 0) {
			throw new \LogicException('LocalBarrierHttpServer has not been started.');
		}

		return 'http://127.0.0.1:'.$this->port;
	}

	public function holdUrl(string $gate, string $body = 'held', int $status = 200, string $contentType = 'text/plain', float $timeout = 3.0): string
	{
		return $this->baseUrl().'/hold?'.http_build_query([
			'gate' => $gate,
			'body' => base64_encode($body),
			'status' => $status,
			'content_type' => $contentType,
			'timeout' => $timeout,
		]);
	}

	public function fastUrl(string $name, ?string $signalGate = null, string $body = 'ok', int $status = 200, string $contentType = 'text/plain'): string
	{
		$query = [
			'name' => $name,
			'body' => base64_encode($body),
			'status' => $status,
			'content_type' => $contentType,
		];
		if (null !== $signalGate) {
			$query['signal'] = $signalGate;
		}

		return $this->baseUrl().'/fast?'.http_build_query($query);
	}

	/** @param list<array<string,mixed>> $rules */
	public function configure(array $rules): void
	{
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\n",
				'content' => json_encode(['rules' => $rules], JSON_THROW_ON_ERROR),
				'timeout' => 2.0,
			],
		]);
		$result = @file_get_contents($this->baseUrl().'/_configure', false, $context);
		if (false === $result) {
			throw new \RuntimeException('Failed to configure LocalBarrierHttpServer routes.');
		}
	}

	public function signal(string $gate): void
	{
		$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2.0]]);
		@file_get_contents($this->baseUrl().'/signal?'.http_build_query(['gate' => $gate]), false, $context);
	}

	/** @return array{events:list<string>,held:array<string,int>} */
	public function inspect(): array
	{
		$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2.0]]);
		$raw = @file_get_contents($this->baseUrl().'/inspect', false, $context);
		if (false === $raw) {
			return ['events' => [], 'held' => []];
		}
		$decoded = json_decode($raw, true);

		return is_array($decoded)
			? ['events' => $decoded['events'] ?? [], 'held' => $decoded['held'] ?? []]
			: ['events' => [], 'held' => []];
	}

	public function stop(): void
	{
		if (is_resource($this->process)) {
			@proc_terminate($this->process);
			@proc_close($this->process);
			$this->process = null;
		}
		$this->port = 0;
	}

	public function __destruct()
	{
		$this->stop();
	}
}
