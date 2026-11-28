<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskException;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Security\GuestApiTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Proxies public and signed asset delivery to the database-owning API runtime. */
final readonly class PublicAssetApiProxy
{
	/** @var list<string> */
	private const FORWARDED_QUERY_PARAMS = ['v', 'exp', 'sig', 'site', 'd'];

	/** @var list<string> */
	private const FORWARDED_REQUEST_HEADERS = ['If-None-Match', 'If-Modified-Since', 'Range'];

	/** @var list<string> */
	private const PASSTHROUGH_RESPONSE_HEADERS = [
		'content-type',
		'content-disposition',
		'cache-control',
		'x-content-type-options',
		'content-security-policy',
		'content-length',
		'location',
		'etag',
		'last-modified',
		'accept-ranges',
		'content-range',
		'vary',
	];

	public function __construct(
		private BusinessApiClient $api,
		private ApiSessionTokenProvider $sessionTokens,
		private FiberTaskRunner $tasks,
		private GuestApiTokenManager $guestTokens,
	) {
	}

	public function file(Request $request, string $publicToken, string $variant): Response
	{
		return $this->proxyPath($request, '/api/public/assets/'.$publicToken.'/'.$variant);
	}

	public function signedFile(Request $request, string $publicToken, string $variant): Response
	{
		return $this->proxyPath($request, '/api/public/assets/signed/'.$publicToken.'/'.$variant);
	}

	public function pluginAsset(Request $request, string $slug, string $path): Response
	{
		if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) {
			return new Response('', Response::HTTP_NOT_FOUND);
		}
		if ('' === $path || str_starts_with($path, '/') || 1 === preg_match('/[\x00-\x1f\x7f\\\\?#]/', $path)) {
			return new Response('', Response::HTTP_NOT_FOUND);
		}

		$segments = explode('/', $path);
		$encodedSegments = [];
		foreach ($segments as $segment) {
			if ('' === $segment || '.' === $segment || '..' === $segment || str_starts_with($segment, '.')) {
				return new Response('', Response::HTTP_NOT_FOUND);
			}
			$encodedSegments[] = rawurlencode($segment);
		}

		return $this->proxyPath($request, '/api/public/plugin-assets/'.$slug.'/'.implode('/', $encodedSegments));
	}

	private function proxyPath(Request $request, string $apiPath): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$method = $request->isMethod('HEAD') ? 'HEAD' : 'GET';
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
		];
		foreach (self::FORWARDED_REQUEST_HEADERS as $headerName) {
			$headerValue = $request->headers->get($headerName);
			if (is_string($headerValue) && '' !== $headerValue && !str_contains($headerValue, "\r") && !str_contains($headerValue, "\n")) {
				$headers[$headerName] = $headerValue;
			}
		}

		$query = [];
		foreach (self::FORWARDED_QUERY_PARAMS as $param) {
			if ($request->query->has($param)) {
				$value = $request->query->get($param);
				if (is_string($value) && '' !== $value) {
					$query[$param] = $value;
				}
			}
		}

		try {
			$result = $this->tasks->run([
				'asset' => function (FiberHttpClient $http) use ($request, $method, $apiPath, $siteHost, $guestToken, $query, $headers): array {
					$token = $this->sessionTokens->tokenFor($http, $request, $siteHost) ?? $guestToken;

					return $this->api->requestRawAsync(
						$http,
						$method,
						$apiPath,
						$token,
						query: $query,
						headers: $headers,
						timeout: 30.0,
					);
				},
			], 30.0)['asset'];
		} catch (FiberTaskException) {
			return new Response('', Response::HTTP_BAD_GATEWAY);
		} catch (\Throwable) {
			return new Response('', Response::HTTP_BAD_GATEWAY);
		}

		$status = $result['status'];
		$isPassthroughStatus = ($status >= 200 && $status < 400) || Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE === $status;
		if (!$isPassthroughStatus) {
			return new Response('', in_array($status, [401, 403, 404], true) ? $status : Response::HTTP_BAD_GATEWAY);
		}

		$responseHeaders = [];
		foreach (self::PASSTHROUGH_RESPONSE_HEADERS as $name) {
			$value = $result['headers'][$name][0] ?? null;
			if (is_string($value)) {
				$responseHeaders[$name] = $value;
			}
		}

		$body = ('HEAD' === $method || Response::HTTP_NOT_MODIFIED === $status || Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE === $status) ?
			'' :
			$result['body'];

		return new Response($body, $status, $responseHeaders);
	}
}
