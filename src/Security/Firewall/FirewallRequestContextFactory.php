<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class FirewallRequestContextFactory
{
	private const MAX_VALUES = 10000;
	private const MAX_INPUT_DEPTH = 32;
	private const MAX_JSON_BYTES = 1048576;

	public function __construct(
		private readonly int $maxInspectionBytes = 8388608,
		private readonly int $maxFiles = 20,
		private readonly int $maxInspectionMilliseconds = 5000,
	) {
	}

	public function create(Request $request, int|string|null $siteId): FirewallRequestContext
	{
		if ($this->maxInspectionBytes < 1 || $this->maxFiles < 0 || $this->maxInspectionMilliseconds < 1) {
			throw new \LogicException('Firewall inspection limits must be positive.');
		}
		$started = hrtime(true);
		$bytes = 0;
		$valueCount = 0;
		$values = [];
		$add = function (string $target, ?string $name, string $value) use (&$values, &$bytes, &$valueCount, $started): void {
			if (++$valueCount > self::MAX_VALUES) {
				throw new FirewallInspectionException('Request contains too many values for complete inspection.');
			}
			$bytes += strlen($value) + strlen($name ?? '');
			$this->assertWithinLimits($started, $bytes);
			$values[$target][] = ['name' => $name, 'value' => $value];
		};

		$add('uri', null, $request->getRequestUri());
		$add('path', null, $request->getPathInfo());
		$add('method', null, $request->getMethod());
		$add('ip', null, (string) $request->getClientIp());
		foreach ($request->headers->all() as $name => $headerValues) {
			foreach ($headerValues as $value) {
				$add('header', (string) $name, (string) $value);
			}
		}
		$this->addScalars($request->cookies->all(), 'cookie', $add);
		$this->addScalars($request->query->all(), 'query', $add);
		$this->addScalars($request->request->all(), 'field', $add);

		$contentType = strtolower((string) $request->headers->get('Content-Type', ''));
		$isMultipart = str_starts_with($contentType, 'multipart/form-data');
		if (!$isMultipart) {
			$contentLength = $request->headers->get('Content-Length');
			if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $this->maxInspectionBytes - $bytes) {
				throw new FirewallInspectionException('Request body exceeds the configured inspection limit.');
			}
			$body = $this->readBoundedBody($request, $bytes, $started);
			if ('' !== $body) {
				$add('body', null, $body);
				if (str_contains($contentType, 'json')) {
					if (strlen($body) > self::MAX_JSON_BYTES) {
						throw new FirewallInspectionException('JSON body exceeds the structured inspection limit.');
					}
					try {
						$decoded = json_decode($body, true, self::MAX_INPUT_DEPTH, JSON_THROW_ON_ERROR);
					} catch (\JsonException $exception) {
						throw new FirewallInspectionException('JSON body could not be parsed for complete inspection.', previous: $exception);
					}
					if (is_array($decoded)) {
						$this->addScalars($decoded, 'field', $add);
					}
				}
			}
		}

		$fileCount = 0;
		$this->addUploads($request->files->all(), $add, $fileCount, $bytes, $started);

		return new FirewallRequestContext(
			$values,
			$siteId,
			$request->getMethod(),
			$request->getPathInfo(),
			(string) $request->getClientIp(),
			$started + ($this->maxInspectionMilliseconds * 1_000_000),
		);
	}

	/**
	 * @param array<string,mixed>                  $input
	 * @param callable(string,?string,string):void $add
	 */
	private function addScalars(array $input, string $target, callable $add, ?string $prefix = null, int $depth = 0): void
	{
		if ($depth > self::MAX_INPUT_DEPTH) {
			throw new FirewallInspectionException('Request field nesting exceeds the inspection limit.');
		}
		foreach ($input as $name => $value) {
			$key = null === $prefix ? (string) $name : $prefix.'.'.(string) $name;
			if (is_array($value)) {
				$this->addScalars($value, $target, $add, $key, $depth + 1);
			} elseif (is_scalar($value)) {
				$add($target, $key, (string) $value);
			}
		}
	}

	/**
	 * @param array<string,mixed>                  $files
	 * @param callable(string,?string,string):void $add
	 */
	private function addUploads(array $files, callable $add, int &$fileCount, int &$bytes, int $started, int $depth = 0): void
	{
		if ($depth > self::MAX_INPUT_DEPTH) {
			throw new FirewallInspectionException('Request upload nesting exceeds the inspection limit.');
		}
		foreach ($files as $name => $file) {
			if (is_array($file)) {
				$this->addUploads($file, $add, $fileCount, $bytes, $started, $depth + 1);
				continue;
			}
			if (!$file instanceof UploadedFile) {
				throw new FirewallInspectionException('Request contains an unsupported upload value.');
			}
			++$fileCount;
			if ($fileCount > $this->maxFiles) {
				throw new FirewallInspectionException('Request upload count exceeds the configured inspection limit.');
			}
			if (!$file->isValid()) {
				throw new FirewallInspectionException('An uploaded file could not be inspected.');
			}
			$add('upload_name', (string) $name, $file->getClientOriginalName());
			$add('upload_type', (string) $name, (string) $file->getClientMimeType());
			$stream = @fopen($file->getPathname(), 'rb');
			if (false === $stream) {
				throw new FirewallInspectionException('An uploaded file could not be read for inspection.');
			}
			$contents = '';
			try {
				while (!feof($stream)) {
					$chunk = fread($stream, 65536);
					if (false === $chunk) {
						throw new FirewallInspectionException('An uploaded file could not be read for inspection.');
					}
					$contents .= $chunk;
					$this->assertWithinLimits($started, $bytes + strlen($contents));
				}
			} finally {
				fclose($stream);
			}
			$add('upload', (string) $name, $contents);
		}
	}

	private function readBoundedBody(Request $request, int $bytes, int $started): string
	{
		$input = $request->getContent(true);
		if (!is_resource($input)) {
			throw new FirewallInspectionException('Request body stream is unavailable for inspection.');
		}
		$replay = fopen('php://temp/maxmemory:2097152', 'w+b');
		if (false === $replay) {
			fclose($input);
			throw new FirewallInspectionException('Request body could not be staged for inspection.');
		}
		$body = '';
		try {
			while (!feof($input)) {
				$remaining = $this->maxInspectionBytes - $bytes - strlen($body);
				$chunk = fread($input, min(65536, max(1, $remaining + 1)));
				if (false === $chunk || ('' === $chunk && !feof($input))) {
					throw new FirewallInspectionException('Request body stream could not be read completely.');
				}
				if ('' === $chunk) {
					break;
				}
				$body .= $chunk;
				if (strlen($chunk) !== fwrite($replay, $chunk)) {
					throw new FirewallInspectionException('Request body could not be staged for inspection.');
				}
				$this->assertWithinLimits($started, $bytes + strlen($body));
			}
			rewind($replay);
			$request->initialize(
				$request->query->all(),
				$request->request->all(),
				$request->attributes->all(),
				$request->cookies->all(),
				$request->files->all(),
				$request->server->all(),
				$replay,
			);
			$replay = false;

			return $body;
		} finally {
			fclose($input);
			if (is_resource($replay)) {
				fclose($replay);
			}
		}
	}

	private function assertWithinLimits(int $started, int $bytes): void
	{
		if ($bytes > $this->maxInspectionBytes || (hrtime(true) - $started) / 1_000_000 > $this->maxInspectionMilliseconds) {
			throw new FirewallInspectionException('Request exceeds the configured inspection limits.');
		}
	}
}
