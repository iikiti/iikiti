<?php

declare(strict_types=1);

/**
 * Lightweight single-process multiplexed HTTP barrier server for concurrency tests.
 *
 * Usage: php tests/Support/Async/barrier_server.php
 * Prints "READY <port>\n" on stdout once listening on 127.0.0.1.
 */

if (\PHP_SAPI !== 'cli') {
	exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
	fwrite(STDERR, "Failed to bind barrier server: $errstr ($errno)\n");
	exit(1);
}
stream_set_blocking($server, false);

$boundName = stream_socket_get_name($server, false);
if (false === $boundName || false === ($colon = strrpos($boundName, ':'))) {
	fwrite(STDERR, "Failed to inspect bound socket name.\n");
	exit(1);
}
$port = (int) substr($boundName, $colon + 1);
fwrite(STDOUT, "READY {$port}\n");
fflush(STDOUT);

$hardDeadline = microtime(true) + 15.0;
/** @var array<int,array{stream:resource,buffer:string,headersDone:bool,headerEnd:int,contentLength:int}> $reading */
$reading = [];
/** @var array<string,list<array{stream:resource,gate:string,body:string,status:int,contentType:string,deadline:float}>> $held */
$held = [];
/** @var list<array<string,mixed>> $rules */
$rules = [];
/** @var list<string> $events */
$events = [];

$sendResponse = static function ($stream, int $status, string $contentType, string $body): void {
	$reason = match ($status) {
		200 => 'OK',
		404 => 'Not Found',
		409 => 'Conflict',
		500 => 'Internal Server Error',
		504 => 'Gateway Timeout',
		default => 'Response',
	};
	$payload = "HTTP/1.1 {$status} {$reason}\r\n"
		."Content-Type: {$contentType}\r\n"
		.'Content-Length: '.strlen($body)."\r\n"
		."Connection: close\r\n"
		."\r\n"
		.$body;
	$written = 0;
	$len = strlen($payload);
	while ($written < $len) {
		$bytes = @fwrite($stream, substr($payload, $written));
		if (false === $bytes || 0 === $bytes) {
			break;
		}
		$written += $bytes;
	}
	@fclose($stream);
};

$releaseGate = static function (string $gate) use (&$held, &$events, $sendResponse): int {
	if (!isset($held[$gate])) {
		return 0;
	}
	$entries = $held[$gate];
	unset($held[$gate]);
	$count = 0;
	foreach ($entries as $entry) {
		$sendResponse($entry['stream'], $entry['status'], $entry['contentType'], $entry['body']);
		$events[] = 'responded:'.$gate;
		++$count;
	}

	return $count;
};

$matchRule = static function (string $path, array $query) use (&$rules): ?array {
	foreach ($rules as $rule) {
		if (($rule['path'] ?? null) !== $path) {
			continue;
		}
		$expectedFragmentId = $rule['fragmentId'] ?? null;
		if (null !== $expectedFragmentId && ($query['_iikiti_fragment_id'] ?? null) !== $expectedFragmentId) {
			continue;
		}

		return $rule;
	}

	return null;
};

while (microtime(true) < $hardDeadline) {
	$now = microtime(true);
	foreach ($held as $gate => $entries) {
		$stillHeld = [];
		foreach ($entries as $entry) {
			if ($now >= $entry['deadline']) {
				$sendResponse($entry['stream'], 504, 'text/plain', 'Barrier hold timed out');
				$events[] = 'timeout:'.$gate;
				continue;
			}
			$stillHeld[] = $entry;
		}
		if ([] === $stillHeld) {
			unset($held[$gate]);
		} else {
			$held[$gate] = $stillHeld;
		}
	}

	$read = [$server];
	foreach ($reading as $entry) {
		$read[] = $entry['stream'];
	}
	foreach ($held as $entries) {
		foreach ($entries as $entry) {
			$read[] = $entry['stream'];
		}
	}
	$write = null;
	$except = null;
	$selected = @stream_select($read, $write, $except, 0, 10_000);
	if (false === $selected) {
		break;
	}

	foreach ($read as $sock) {
		if ($sock === $server) {
			$client = @stream_socket_accept($server, 0);
			if (false !== $client) {
				stream_set_blocking($client, false);
				$reading[(int) $client] = [
					'stream' => $client,
					'buffer' => '',
					'headersDone' => false,
					'headerEnd' => 0,
					'contentLength' => 0,
				];
			}
			continue;
		}

		$sockId = (int) $sock;
		if (!isset($reading[$sockId])) {
			// Held client socket became readable => check if client closed/cancelled connection.
			$peek = @stream_socket_recvfrom($sock, 1, STREAM_PEEK);
			if ('' === $peek || false === $peek) {
				foreach ($held as $gate => $entries) {
					$remaining = [];
					foreach ($entries as $entry) {
						if ($entry['stream'] === $sock) {
							$events[] = 'aborted:'.$gate;
							@fclose($sock);
							continue;
						}
						$remaining[] = $entry;
					}
					if ([] === $remaining) {
						unset($held[$gate]);
					} else {
						$held[$gate] = $remaining;
					}
				}
			}
			continue;
		}

		$chunk = @fread($sock, 65536);
		if (false === $chunk) {
			unset($reading[$sockId]);
			@fclose($sock);
			continue;
		}
		$reading[$sockId]['buffer'] .= $chunk;
		if (!$reading[$sockId]['headersDone']) {
			$pos = strpos($reading[$sockId]['buffer'], "\r\n\r\n");
			if (false !== $pos) {
				$reading[$sockId]['headersDone'] = true;
				$reading[$sockId]['headerEnd'] = $pos + 4;
				if (1 === preg_match('/^Content-Length:\s*(\d+)/im', substr($reading[$sockId]['buffer'], 0, $pos), $m)) {
					$reading[$sockId]['contentLength'] = (int) $m[1];
				}
			}
		}

		$state = $reading[$sockId];
		if (!$state['headersDone']) {
			if (feof($sock)) {
				unset($reading[$sockId]);
				@fclose($sock);
			}
			continue;
		}
		$bodyReceived = strlen($state['buffer']) - $state['headerEnd'];
		if ($bodyReceived < $state['contentLength']) {
			continue;
		}

		unset($reading[$sockId]);
		$rawHeaders = substr($state['buffer'], 0, $state['headerEnd'] - 4);
		$body = substr($state['buffer'], $state['headerEnd'], $state['contentLength']);
		$lines = explode("\r\n", $rawHeaders);
		$requestLine = explode(' ', $lines[0]);
		$method = strtoupper($requestLine[0]);
		$target = $requestLine[1] ?? '/';
		$path = (string) (parse_url($target, PHP_URL_PATH) ?? '/');
		$query = [];
		parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

		if ('POST' === $method && '/_configure' === $path) {
			$payload = json_decode($body, true);
			$rules = is_array($payload['rules'] ?? null) ? array_values($payload['rules']) : [];
			$sendResponse($sock, 200, 'application/json', '{"ok":true}');
			continue;
		}
		if ('/_health' === $path) {
			$sendResponse($sock, 200, 'text/plain', 'ok');
			continue;
		}
		if ('/inspect' === $path) {
			$heldSummary = [];
			foreach ($held as $gate => $entries) {
				$heldSummary[$gate] = count($entries);
			}
			$sendResponse($sock, 200, 'application/json', json_encode([
				'events' => $events,
				'held' => $heldSummary,
			], JSON_THROW_ON_ERROR));
			continue;
		}
		if ('/signal' === $path) {
			$gate = (string) ($query['gate'] ?? 'default');
			$events[] = 'signal:'.$gate;
			$released = $releaseGate($gate);
			$sendResponse($sock, 200, 'application/json', json_encode(['released' => $released], JSON_THROW_ON_ERROR));
			continue;
		}

		$rule = $matchRule($path, $query);
		if (null !== $rule) {
			$action = (string) ($rule['action'] ?? 'fast');
			$ruleBody = (string) ($rule['body'] ?? '');
			$ruleStatus = (int) ($rule['status'] ?? 200);
			$ruleContentType = (string) ($rule['contentType'] ?? 'application/json');
			if ('hold' === $action) {
				$gate = (string) ($rule['gate'] ?? 'default');
				$timeout = (float) ($rule['timeout'] ?? 3.0);
				$events[] = 'received:'.$gate;
				$held[$gate][] = [
					'stream' => $sock,
					'gate' => $gate,
					'body' => $ruleBody,
					'status' => $ruleStatus,
					'contentType' => $ruleContentType,
					'deadline' => microtime(true) + max(0.1, $timeout),
				];
				continue;
			}
			$name = (string) ($rule['name'] ?? $path);
			$events[] = 'received:'.$name;
			$sendResponse($sock, $ruleStatus, $ruleContentType, $ruleBody);
			$events[] = 'responded:'.$name;
			$signalGate = $rule['signal'] ?? null;
			if (is_string($signalGate) && '' !== $signalGate) {
				$events[] = 'signal:'.$signalGate;
				$releaseGate($signalGate);
			}
			continue;
		}

		if ('/hold' === $path) {
			$gate = (string) ($query['gate'] ?? 'default');
			$decodedBody = isset($query['body']) ? (base64_decode((string) $query['body'], true) ?: '') : 'held';
			$status = (int) ($query['status'] ?? 200);
			$contentType = (string) ($query['content_type'] ?? 'text/plain');
			$timeout = (float) ($query['timeout'] ?? 3.0);
			$events[] = 'received:'.$gate;
			$held[$gate][] = [
				'stream' => $sock,
				'gate' => $gate,
				'body' => $decodedBody,
				'status' => $status,
				'contentType' => $contentType,
				'deadline' => microtime(true) + max(0.1, $timeout),
			];
			continue;
		}

		if ('/fast' === $path) {
			$name = (string) ($query['name'] ?? 'fast');
			$decodedBody = isset($query['body']) ? (base64_decode((string) $query['body'], true) ?: '') : $name;
			$status = (int) ($query['status'] ?? 200);
			$contentType = (string) ($query['content_type'] ?? 'text/plain');
			$events[] = 'received:'.$name;
			$sendResponse($sock, $status, $contentType, $decodedBody);
			$events[] = 'responded:'.$name;
			$signalGate = (string) ($query['signal'] ?? '');
			if ('' !== $signalGate) {
				$events[] = 'signal:'.$signalGate;
				$releaseGate($signalGate);
			}
			continue;
		}

		$sendResponse($sock, 404, 'text/plain', 'Not found');
	}
}

foreach ($reading as $entry) {
	@fclose($entry['stream']);
}
foreach ($held as $entries) {
	foreach ($entries as $entry) {
		@fclose($entry['stream']);
	}
}
@fclose($server);
