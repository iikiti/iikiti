<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

use iikiti\CMS\Kernel;

/** Spawns a multi-worker PHP HTTP server running the test API Kernel. */
final class ApiWorkerServer
{
	private const WORKER_COUNT = 4;

	/** @var list<resource> */
	private array $workerProcesses = [];
	/** @var resource|null */
	private $proxyProcess = null;
	/** @var resource|null */
	private $lockerProcess = null;
	private int $port = 0;
	private string $lockWaitFile = '';
	private string $signalFile = '';
	private string $timelineFile = '';
	private string $stderrFile = '';

	public function warmKernelCache(): void
	{
		$previousRole = getenv('APPLICATION_ROLE');
		putenv('APPLICATION_ROLE=api');
		$_ENV['APPLICATION_ROLE'] = 'api';
		$_SERVER['APPLICATION_ROLE'] = 'api';
		try {
			$kernel = new Kernel('test', false);
			$kernel->boot();
			$kernel->shutdown();
		} finally {
			if (false === $previousRole) {
				putenv('APPLICATION_ROLE');
				unset($_ENV['APPLICATION_ROLE'], $_SERVER['APPLICATION_ROLE']);
			} else {
				putenv('APPLICATION_ROLE='.$previousRole);
				$_ENV['APPLICATION_ROLE'] = $previousRole;
				$_SERVER['APPLICATION_ROLE'] = $previousRole;
			}
		}
	}

	public function startAdvisoryLockHolder(int $lockKey): void
	{
		$this->signalFile = tempnam(sys_get_temp_dir(), 'iikiti-pg-sig-');
		@unlink($this->signalFile);

		$env = $this->baseEnv();
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = @proc_open(
			[\PHP_BINARY, __DIR__.'/pg_advisory_locker.php', (string) $lockKey, $this->signalFile],
			$descriptors,
			$pipes,
			dirname(__DIR__, 3),
			$env,
		);
		if (!is_resource($process)) {
			throw new \RuntimeException('Unable to start pg_advisory_locker.');
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
				if (str_contains($buffer, 'LOCKED')) {
					$this->lockerProcess = $process;
					fclose($pipes[1]);
					fclose($pipes[2]);

					return;
				}
			}
			$status = proc_get_status($process);
			if (!$status['running']) {
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new \RuntimeException('pg_advisory_locker exited prematurely: '.$stderr);
			}
			usleep(10_000);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_terminate($process);
		proc_close($process);
		throw new \RuntimeException('Timed out waiting for pg_advisory_locker.');
	}

	public function start(string $lockedFragmentId, string $signalFragmentId, int $lockKey, string $appSecret): self
	{
		$this->lockWaitFile = tempnam(sys_get_temp_dir(), 'iikiti-pg-wait-');
		@unlink($this->lockWaitFile);
		$this->timelineFile = tempnam(sys_get_temp_dir(), 'iikiti-api-tl-');
		file_put_contents($this->timelineFile, '');
		$this->stderrFile = tempnam(sys_get_temp_dir(), 'iikiti-api-err-');
		file_put_contents($this->stderrFile, '');

		$env = array_merge($this->baseEnv(), [
			'APPLICATION_ROLE' => 'api',
			'APP_SECRET' => $appSecret,
			'IIKITI_TEST_DB_LOCK_FRAGMENT_ID' => $lockedFragmentId,
			'IIKITI_TEST_SIGNAL_FRAGMENT_ID' => $signalFragmentId,
			'IIKITI_TEST_DB_LOCK_KEY' => (string) $lockKey,
			'IIKITI_TEST_LOCK_WAIT_FILE' => $this->lockWaitFile,
			'IIKITI_TEST_SIGNAL_FILE' => $this->signalFile,
			'IIKITI_TEST_TIMELINE_FILE' => $this->timelineFile,
		]);
		unset($env['PHP_CLI_SERVER_WORKERS']);

		$workerPorts = [];
		for ($i = 0; $i < self::WORKER_COUNT; ++$i) {
			$started = false;
			for ($attempt = 0; $attempt < 3; ++$attempt) {
				$workerPort = $this->findFreePort();
				$descriptors = [
					0 => ['pipe', 'r'],
					1 => ['pipe', 'w'],
					2 => ['file', $this->stderrFile, 'a'],
				];
				$process = @proc_open(
					[\PHP_BINARY, '-S', '127.0.0.1:'.$workerPort, __DIR__.'/api_worker_router.php'],
					$descriptors,
					$pipes,
					dirname(__DIR__, 3),
					$env,
				);
				if (!is_resource($process)) {
					continue;
				}
				fclose($pipes[0]);
				fclose($pipes[1]);

				if ($this->waitForHealth($workerPort, $process)) {
					$this->workerProcesses[] = $process;
					$workerPorts[] = (string) $workerPort;
					$started = true;
					break;
				}

				@proc_terminate($process);
				@proc_close($process);
			}
			if (!$started) {
				$this->stopWorkersAndProxy();
				throw new \RuntimeException('Unable to start ApiWorkerServer backend worker '.$i.'.');
			}
		}

		$proxyDescriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['file', $this->stderrFile, 'a'],
		];
		$proxyProcess = @proc_open(
			array_merge([\PHP_BINARY, __DIR__.'/api_worker_proxy.php'], $workerPorts),
			$proxyDescriptors,
			$proxyPipes,
			dirname(__DIR__, 3),
			$env,
		);
		if (!is_resource($proxyProcess)) {
			$this->stopWorkersAndProxy();
			throw new \RuntimeException('Unable to start ApiWorkerServer proxy.');
		}
		fclose($proxyPipes[0]);
		stream_set_blocking($proxyPipes[1], false);

		$deadline = microtime(true) + 3.0;
		$buffer = '';
		while (microtime(true) < $deadline) {
			$chunk = fread($proxyPipes[1], 1024);
			if (is_string($chunk) && '' !== $chunk) {
				$buffer .= $chunk;
				if (1 === preg_match('/READY:(\d+)/', $buffer, $matches)) {
					fclose($proxyPipes[1]);
					$this->proxyProcess = $proxyProcess;
					$this->port = (int) $matches[1];

					return $this;
				}
			}
			$status = proc_get_status($proxyProcess);
			if (!$status['running']) {
				fclose($proxyPipes[1]);
				proc_close($proxyProcess);
				$this->stopWorkersAndProxy();
				throw new \RuntimeException('api_worker_proxy exited prematurely: '.$this->stderr());
			}
			usleep(10_000);
		}

		fclose($proxyPipes[1]);
		@proc_terminate($proxyProcess);
		@proc_close($proxyProcess);
		$this->stopWorkersAndProxy();
		throw new \RuntimeException('Timed out waiting for api_worker_proxy READY.');
	}

	public function baseUrl(): string
	{
		if ($this->port <= 0) {
			throw new \LogicException('ApiWorkerServer has not been started.');
		}

		return 'http://127.0.0.1:'.$this->port;
	}

	/** @return list<string> */
	public function timeline(): array
	{
		if ('' === $this->timelineFile || !file_exists($this->timelineFile)) {
			return [];
		}
		$lines = file($this->timelineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		return is_array($lines) ? $lines : [];
	}

	public function stderr(): string
	{
		if ('' === $this->stderrFile || !file_exists($this->stderrFile)) {
			return '';
		}
		$contents = file_get_contents($this->stderrFile);

		return false === $contents ? '' : trim($contents);
	}

	public function stop(): void
	{
		if ('' !== $this->signalFile) {
			@file_put_contents($this->signalFile, "stop\n");
		}
		if (is_resource($this->lockerProcess)) {
			@proc_terminate($this->lockerProcess);
			@proc_close($this->lockerProcess);
			$this->lockerProcess = null;
		}
		$this->stopWorkersAndProxy();
		if ('' !== $this->lockWaitFile && file_exists($this->lockWaitFile)) {
			@unlink($this->lockWaitFile);
		}
		if ('' !== $this->signalFile && file_exists($this->signalFile)) {
			@unlink($this->signalFile);
		}
		if ('' !== $this->timelineFile && file_exists($this->timelineFile)) {
			@unlink($this->timelineFile);
		}
		if ('' !== $this->stderrFile && file_exists($this->stderrFile)) {
			@unlink($this->stderrFile);
		}
		$this->lockWaitFile = '';
		$this->signalFile = '';
		$this->timelineFile = '';
		$this->stderrFile = '';
		$this->port = 0;
	}

	public function __destruct()
	{
		$this->stop();
	}

	private function findFreePort(): int
	{
		$sock = @stream_socket_server('tcp://127.0.0.1:0');
		if (false === $sock) {
			throw new \RuntimeException('Unable to allocate free loopback port.');
		}
		$name = stream_socket_get_name($sock, false);
		fclose($sock);
		if (false === $name || false === ($pos = strrpos($name, ':'))) {
			throw new \RuntimeException('Unable to inspect allocated port.');
		}

		return (int) substr($name, $pos + 1);
	}

	/** @param resource $process */
	private function waitForHealth(int $port, $process): bool
	{
		$deadline = microtime(true) + 3.0;
		$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 0.2]]);
		while (microtime(true) < $deadline) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				return false;
			}
			$resp = @file_get_contents('http://127.0.0.1:'.$port.'/_health', false, $context);
			if ('ok' === $resp) {
				return true;
			}
			usleep(15_000);
		}

		return false;
	}

	/** @return array<string,string> */
	private function baseEnv(): array
	{
		$env = [];
		foreach ($_ENV as $key => $value) {
			if (is_string($key) && is_scalar($value)) {
				$env[$key] = (string) $value;
			}
		}
		foreach ($_SERVER as $key => $value) {
			if (is_string($key) && is_scalar($value) && !isset($env[$key])) {
				$env[$key] = (string) $value;
			}
		}
		$env['APP_ENV'] = 'test';

		return $env;
	}

	private function stopWorkersAndProxy(): void
	{
		if (is_resource($this->proxyProcess)) {
			@proc_terminate($this->proxyProcess);
			@proc_close($this->proxyProcess);
			$this->proxyProcess = null;
		}
		foreach ($this->workerProcesses as $workerProcess) {
			if (is_resource($workerProcess)) {
				@proc_terminate($workerProcess);
				@proc_close($workerProcess);
			}
		}
		$this->workerProcesses = [];
	}
}
