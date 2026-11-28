<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds CDN-ready HTTP responses for local and remote digital asset storage backends
 * with strong ETags, conditional 304 responses, byte-range streaming (206/416), HEAD
 * support, Cache-Control policies, and optional presigned/CDN redirects.
 */
final readonly class AssetStreamResponseFactory
{
	public const DELIVERY_MODE_PROXY = 'proxy';
	public const DELIVERY_MODE_REDIRECT = 'redirect';

	/** @var list<string> */
	private const INTERNAL_HLS_INLINE_MIMES = [
		'application/vnd.apple.mpegurl',
		'video/mp2t',
	];

	private AssetMimeTypeRegistry $mimeRegistry;

	public function __construct(
		private AssetStorageInterface $storage,
		?AssetMimeTypeRegistry $mimeRegistry = null,
		#[Autowire('%asset_delivery_mode%')]
		private string $deliveryMode = self::DELIVERY_MODE_PROXY,
		#[Autowire('%asset_cdn_base_url%')]
		private string $cdnBaseUrl = '',
		#[Autowire('%asset_redirect_ttl_seconds%')]
		private int $redirectTtlSeconds = 300,
	) {
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
	}

	public function createResponse(
		Request $request,
		string $storageKey,
		string $mimeType,
		string $filename,
		string $visibility,
		?string $checksum = null,
		?int $knownSize = null,
		?string $forcedDisposition = null,
		bool $isSigned = false,
		bool $allowInternalHlsInline = false,
	): Response {
		$safeFilename = $this->sanitizeFilename($filename);
		$disposition = $this->resolveDisposition($mimeType, $forcedDisposition, $allowInternalHlsInline);
		$cacheControl = $this->resolveCacheControl($request, $visibility, $checksum, $isSigned);

		if (self::DELIVERY_MODE_REDIRECT === strtolower(trim($this->deliveryMode))) {
			$redirect = $this->tryCreateRedirectResponse($storageKey, $mimeType, $safeFilename, $visibility, $disposition, $isSigned);
			if (null !== $redirect) {
				return $redirect;
			}
		}

		if ($this->storage->isLocal()) {
			return $this->createLocalResponse($request, $storageKey, $mimeType, $safeFilename, $disposition, $cacheControl, $visibility, $checksum, $isSigned);
		}

		return $this->createRemoteResponse($request, $storageKey, $mimeType, $safeFilename, $disposition, $cacheControl, $checksum, $knownSize);
	}

	private function tryCreateRedirectResponse(
		string $storageKey,
		string $mimeType,
		string $safeFilename,
		string $visibility,
		string $disposition,
		bool $isSigned,
	): ?RedirectResponse {
		$cdnBase = trim($this->cdnBaseUrl);
		if ('public' === $visibility && !$isSigned && '' !== $cdnBase) {
			if (!$this->storage->exists($storageKey)) {
				throw new NotFoundHttpException('Asset file not found.');
			}
			$publicUrl = $this->storage->publicUrl($storageKey) ?? rtrim($cdnBase, '/').'/'.ltrim($storageKey, '/');
			if ($this->isValidHttpUrl($publicUrl)) {
				return new RedirectResponse($publicUrl, Response::HTTP_FOUND, ['Cache-Control' => 'private, no-store']);
			}
		}

		if ($this->storage->supportsTemporaryUrls()) {
			if (!$this->storage->exists($storageKey)) {
				throw new NotFoundHttpException('Asset file not found.');
			}
			$ttl = max(1, min(3600, $this->redirectTtlSeconds));
			$expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttl));
			$temporaryUrl = $this->storage->temporaryUrl($storageKey, $expiresAt, [
				'mimeType' => $mimeType,
				'filename' => $safeFilename,
				'disposition' => $disposition,
			]);
			if (null !== $temporaryUrl && $this->isValidHttpUrl($temporaryUrl)) {
				return new RedirectResponse($temporaryUrl, Response::HTTP_FOUND, ['Cache-Control' => 'private, no-store']);
			}
		}

		return null;
	}

	private function createLocalResponse(
		Request $request,
		string $storageKey,
		string $mimeType,
		string $safeFilename,
		string $disposition,
		string $cacheControl,
		string $visibility,
		?string $checksum,
		bool $isSigned,
	): Response {
		$path = $this->storage->pathFor($storageKey);
		if (!is_file($path)) {
			throw new NotFoundHttpException('Asset file not found.');
		}

		$etag = $this->normalizeChecksum($checksum);
		if (null === $etag) {
			$computed = hash_file('sha256', $path);
			$etag = is_string($computed) ? $computed : null;
			if (null !== $etag) {
				$cacheControl = $this->resolveCacheControl($request, $visibility, $etag, $isSigned);
			}
		}

		$response = new BinaryFileResponse($path, Response::HTTP_OK, [], true, null, false, true);
		$response->headers->set('Content-Type', $mimeType);
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('Cache-Control', $cacheControl);
		$response->headers->set('Accept-Ranges', 'bytes');
		$response->setContentDisposition($disposition, $safeFilename);
		if (null !== $etag) {
			$response->setEtag($etag, false);
		}

		if ($response->isNotModified($request)) {
			return $response;
		}

		return $response->prepare($request);
	}

	private function createRemoteResponse(
		Request $request,
		string $storageKey,
		string $mimeType,
		string $safeFilename,
		string $disposition,
		string $cacheControl,
		?string $checksum,
		?int $knownSize,
	): Response {
		if (!$this->storage->exists($storageKey)) {
			throw new NotFoundHttpException('Asset file not found.');
		}

		$etag = $this->normalizeChecksum($checksum);
		$totalSize = $knownSize ?? $this->storage->size($storageKey);

		$probe = new Response('', Response::HTTP_OK);
		$probe->headers->set('Cache-Control', $cacheControl);
		$probe->headers->set('X-Content-Type-Options', 'nosniff');
		$probe->headers->set('Accept-Ranges', 'bytes');
		if (null !== $etag) {
			$probe->setEtag($etag, false);
		}
		if ($probe->isNotModified($request)) {
			return $probe;
		}

		$status = Response::HTTP_OK;
		$start = 0;
		$length = $totalSize;
		$contentRange = null;

		$rangeHeader = $request->headers->get('Range');
		if (is_string($rangeHeader) && '' !== trim($rangeHeader) && null !== $totalSize) {
			$parsedRange = $this->parseByteRange($rangeHeader, $totalSize);
			if ('unsatisfiable' === $parsedRange['state']) {
				$errorResponse = new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE);
				$errorResponse->headers->set('Content-Range', sprintf('bytes */%d', $totalSize));
				$errorResponse->headers->set('Accept-Ranges', 'bytes');
				$errorResponse->headers->set('Cache-Control', $cacheControl);
				$errorResponse->headers->set('X-Content-Type-Options', 'nosniff');

				return $errorResponse;
			}
			if ('range' === $parsedRange['state']) {
				$status = Response::HTTP_PARTIAL_CONTENT;
				$start = $parsedRange['start'];
				$end = $parsedRange['end'];
				$length = $end - $start + 1;
				$contentRange = sprintf('bytes %d-%d/%d', $start, $end, $totalSize);
			}
		}

		if ($request->isMethod('HEAD')) {
			$headResponse = new Response('', $status);
			$this->applyCommonHeaders($headResponse, $mimeType, $safeFilename, $disposition, $cacheControl, $etag, $length, $contentRange);

			return $headResponse;
		}

		$storage = $this->storage;
		$response = new StreamedResponse(static function () use ($storage, $storageKey, $start, $length): void {
			$stream = $storage->readStream($storageKey);
			if (!is_resource($stream)) {
				throw new \RuntimeException('Storage backend did not return a readable stream.');
			}

			try {
				if ($start > 0) {
					$meta = stream_get_meta_data($stream);
					if (($meta['seekable'] ?? false) && 0 === @fseek($stream, $start, SEEK_SET)) {
						// Positioned directly via fseek.
					} else {
						$remainingSkip = $start;
						while ($remainingSkip > 0 && !feof($stream)) {
							$skipped = fread($stream, min(8192, $remainingSkip));
							if (false === $skipped || '' === $skipped) {
								break;
							}
							$remainingSkip -= strlen($skipped);
						}
					}
				}

				if (null === $length) {
					while (!feof($stream)) {
						$chunk = fread($stream, 8192);
						if (false === $chunk || '' === $chunk) {
							break;
						}
						echo $chunk;
					}
				} else {
					$remaining = $length;
					while ($remaining > 0 && !feof($stream)) {
						$chunk = fread($stream, min(8192, $remaining));
						if (false === $chunk || '' === $chunk) {
							break;
						}
						echo $chunk;
						$remaining -= strlen($chunk);
					}
				}
			} finally {
				fclose($stream);
			}
		}, $status);

		$this->applyCommonHeaders($response, $mimeType, $safeFilename, $disposition, $cacheControl, $etag, $length, $contentRange);

		return $response;
	}

	private function applyCommonHeaders(
		Response $response,
		string $mimeType,
		string $safeFilename,
		string $disposition,
		string $cacheControl,
		?string $etag,
		?int $contentLength,
		?string $contentRange,
	): void {
		$response->headers->set('Content-Type', $mimeType);
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('Cache-Control', $cacheControl);
		$response->headers->set('Accept-Ranges', 'bytes');
		$response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $safeFilename));
		if (null !== $contentLength) {
			$response->headers->set('Content-Length', (string) $contentLength);
		}
		if (null !== $contentRange) {
			$response->headers->set('Content-Range', $contentRange);
		}
		if (null !== $etag) {
			$response->setEtag($etag, false);
		}
	}

	/**
	 * Parses a single RFC 9110 byte-range header.
	 *
	 * @return array{state: 'none'|'unsatisfiable'}|array{state: 'range', start: int, end: int}
	 */
	private function parseByteRange(string $header, int $totalSize): array
	{
		$trimmed = trim($header);
		if (!str_starts_with(strtolower($trimmed), 'bytes=')) {
			return ['state' => 'none'];
		}

		$spec = trim(substr($trimmed, 6));
		if ('' === $spec || str_contains($spec, ',')) {
			return ['state' => 'none'];
		}
		if ($totalSize <= 0) {
			return ['state' => 'unsatisfiable'];
		}
		if (1 !== preg_match('/^(\d*)-(\d*)$/', $spec, $matches)) {
			return ['state' => 'unsatisfiable'];
		}

		$startStr = $matches[1];
		$endStr = $matches[2];
		if ('' === $startStr && '' === $endStr) {
			return ['state' => 'unsatisfiable'];
		}

		if ('' === $startStr) {
			$suffixLength = (int) $endStr;
			if ($suffixLength <= 0) {
				return ['state' => 'unsatisfiable'];
			}
			$start = max(0, $totalSize - $suffixLength);
			$end = $totalSize - 1;
		} else {
			$start = (int) $startStr;
			$end = '' === $endStr ? $totalSize - 1 : min((int) $endStr, $totalSize - 1);
		}

		if ($start < 0 || $start >= $totalSize || $start > $end) {
			return ['state' => 'unsatisfiable'];
		}

		return ['state' => 'range', 'start' => $start, 'end' => $end];
	}

	private function resolveCacheControl(Request $request, string $visibility, ?string $checksum, bool $isSigned): string
	{
		if ($isSigned || 'private' === $visibility) {
			return 'private, no-store';
		}

		$version = $request->query->get('v');
		$normalizedChecksum = $this->normalizeChecksum($checksum);
		if (is_string($version) && null !== $normalizedChecksum && 1 === preg_match('/^[a-f0-9]{8,64}$/i', $version) && str_starts_with($normalizedChecksum, strtolower($version))) {
			return 'public, max-age=31536000, immutable';
		}

		return 'public, max-age=3600, stale-while-revalidate=86400';
	}

	private function resolveDisposition(string $mimeType, ?string $forcedDisposition, bool $allowInternalHlsInline = false): string
	{
		$normalizedMime = strtolower(trim($mimeType));
		$isInlineSafe = $this->mimeRegistry->isInlineDeliverable($normalizedMime)
			|| ($allowInternalHlsInline && in_array($normalizedMime, self::INTERNAL_HLS_INLINE_MIMES, true));

		if (null !== $forcedDisposition) {
			if (ResponseHeaderBag::DISPOSITION_INLINE === $forcedDisposition && $isInlineSafe) {
				return ResponseHeaderBag::DISPOSITION_INLINE;
			}

			return ResponseHeaderBag::DISPOSITION_ATTACHMENT;
		}

		return $isInlineSafe ?
			ResponseHeaderBag::DISPOSITION_INLINE :
			ResponseHeaderBag::DISPOSITION_ATTACHMENT;
	}

	private function sanitizeFilename(string $filename): string
	{
		$cleaned = basename(str_replace('\\', '/', $filename));
		$cleaned = preg_replace('/[\x00-\x1f\x7f"\/\\\\]/', '', $cleaned) ?? '';
		$cleaned = trim($cleaned);

		return '' !== $cleaned ? $cleaned : 'asset';
	}

	private function normalizeChecksum(?string $checksum): ?string
	{
		if (null === $checksum) {
			return null;
		}
		$normalized = strtolower(trim($checksum));

		return 1 === preg_match('/^[a-f0-9]{64}$/', $normalized) ? $normalized : null;
	}

	private function isValidHttpUrl(string $url): bool
	{
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));

		return in_array($scheme, ['http', 'https'], true) && !empty($parts['host']);
	}
}
