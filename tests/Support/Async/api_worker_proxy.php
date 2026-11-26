<?php

declare(strict_types=1);

$rawArgs = $_SERVER['argv'] ?? [];
$workerPorts = [];
if (is_array($rawArgs)) {
	foreach (array_slice($rawArgs, 1) as $arg) {
		$port = (int) $arg;
		if ($port > 0) {
			$workerPorts[] = $port;
		}
	}
}

if ([] === $workerPorts) {
	fwrite(STDERR, "Usage: php api_worker_proxy.php <worker_port> [<worker_port>...]\n");
	exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
	fwrite(STDERR, sprintf("Failed to bind worker proxy socket: %s (%d)\n", $errstr, $errno));
	exit(1);
}

$localName = stream_socket_get_name($server, false);
if (false === $localName || false === ($colonPos = strrpos($localName, ':'))) {
	fclose($server);
	fwrite(STDERR, "Failed to inspect bound worker proxy port.\n");
	exit(1);
}

stream_set_blocking($server, false);
$proxyPort = (int) substr($localName, $colonPos + 1);
fwrite(STDOUT, sprintf("READY:%d\n", $proxyPort));
fflush(STDOUT);

/** @var list<int<1, max>> $idleWorkers */
$idleWorkers = $workerPorts;
/** @var list<resource> $waitingClients */
$waitingClients = [];
/** @var list<array{client: resource, backend: resource, port: int<1, max>, toBackend: string, toClient: string, clientEof: bool, backendEof: bool}> $pairs */
$pairs = [];

$dispatchWaiting = static function () use (&$idleWorkers, &$waitingClients, &$pairs): void {
	while ([] !== $waitingClients && [] !== $idleWorkers) {
		$workerPort = array_shift($idleWorkers);
		$client = array_shift($waitingClients);
		if (!is_resource($client)) {
			array_unshift($idleWorkers, $workerPort);
			break;
		}

		$backend = @stream_socket_client(
			'tcp://127.0.0.1:'.$workerPort,
			$connectErrno,
			$connectErrstr,
			1.0,
			STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
		);
		if (false === $backend) {
			$idleWorkers[] = $workerPort;
			@fclose($client);
			continue;
		}

		stream_set_blocking($backend, false);
		$pairs[] = [
			'client' => $client,
			'backend' => $backend,
			'port' => $workerPort,
			'toBackend' => '',
			'toClient' => '',
			'clientEof' => false,
			'backendEof' => false,
		];
	}
};

$hardDeadline = microtime(true) + 15.0;
while (microtime(true) < $hardDeadline) {
	$dispatchWaiting();

	/** @var list<resource> $read */
	$read = [$server];
	/** @var list<resource> $write */
	$write = [];
	/** @var list<resource>|null $except */
	$except = null;

	foreach ($pairs as $pair) {
		if (!$pair['clientEof'] && strlen($pair['toBackend']) < 1_048_576) {
			$read[] = $pair['client'];
		}
		if (!$pair['backendEof'] && strlen($pair['toClient']) < 1_048_576) {
			$read[] = $pair['backend'];
		}
		if ('' !== $pair['toBackend']) {
			$write[] = $pair['backend'];
		}
		if ('' !== $pair['toClient']) {
			$write[] = $pair['client'];
		}
	}

	$ready = @stream_select($read, $write, $except, 0, 50_000);
	if (false === $ready) {
		continue;
	}

	if (in_array($server, $read, true)) {
		while (is_resource($client = @stream_socket_accept($server, 0))) {
			stream_set_blocking($client, false);
			$waitingClients[] = $client;
		}
	}

	foreach ($pairs as $index => $pair) {
		$client = $pair['client'];
		$backend = $pair['backend'];

		if (in_array($client, $read, true)) {
			$chunk = @fread($client, 65_536);
			if (false === $chunk || ('' === $chunk && @feof($client))) {
				$pair['clientEof'] = true;
			} elseif ('' !== $chunk) {
				$pair['toBackend'] .= $chunk;
			}
		}

		if (in_array($backend, $read, true)) {
			$chunk = @fread($backend, 65_536);
			if (false === $chunk || ('' === $chunk && @feof($backend))) {
				$pair['backendEof'] = true;
			} elseif ('' !== $chunk) {
				$pair['toClient'] .= $chunk;
			}
		}

		if ('' !== $pair['toBackend'] && in_array($backend, $write, true)) {
			$written = @fwrite($backend, $pair['toBackend']);
			if (false === $written) {
				$pair['backendEof'] = true;
				$pair['toBackend'] = '';
			} elseif ($written > 0) {
				$pair['toBackend'] = substr($pair['toBackend'], $written);
			}
		}

		if ('' !== $pair['toClient'] && in_array($client, $write, true)) {
			$written = @fwrite($client, $pair['toClient']);
			if (false === $written) {
				$pair['clientEof'] = true;
				$pair['toClient'] = '';
			} elseif ($written > 0) {
				$pair['toClient'] = substr($pair['toClient'], $written);
			}
		}

		$finished = ($pair['backendEof'] && '' === $pair['toClient'])
			|| ($pair['clientEof'] && '' === $pair['toClient']);
		if ($finished) {
			@fclose($client);
			@fclose($backend);
			$idleWorkers[] = $pair['port'];
			unset($pairs[$index]);
			continue;
		}

		$pairs[$index] = $pair;
	}

	if ([] !== $pairs) {
		$pairs = array_values($pairs);
	}
}
