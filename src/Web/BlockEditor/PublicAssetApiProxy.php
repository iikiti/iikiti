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

/** Proxies public asset delivery to the database-owning API runtime. */
final readonly class PublicAssetApiProxy
{
	public function __construct(
		private BusinessApiClient $api,
		private ApiSessionTokenProvider $sessionTokens,
		private FiberTaskRunner $tasks,
		private GuestApiTokenManager $guestTokens,
	) {
	}

	public function file(Request $request, string $publicToken, string $variant): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
		];

		try {
			$result = $this->tasks->run([
				'asset' => function (FiberHttpClient $http) use ($request, $publicToken, $variant, $siteHost, $guestToken, $headers): array {
					$token = $this->sessionTokens->tokenFor($http, $request, $siteHost) ?? $guestToken;

					return $this->api->requestRawAsync(
						$http,
						'GET',
						'/api/public/assets/'.$publicToken.'/'.$variant,
						$token,
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
		if ($status < 200 || $status >= 400) {
			return new Response('', in_array($status, [401, 403, 404], true) ? $status : Response::HTTP_BAD_GATEWAY);
		}

		$responseHeaders = [];
		foreach (['content-type', 'content-disposition', 'cache-control', 'x-content-type-options', 'content-length', 'location', 'etag', 'last-modified'] as $name) {
			$value = $result['headers'][$name][0] ?? null;
			if (is_string($value)) {
				$responseHeaders[$name] = $value;
			}
		}

		return new Response($result['body'], $status, $responseHeaders);
	}
}
