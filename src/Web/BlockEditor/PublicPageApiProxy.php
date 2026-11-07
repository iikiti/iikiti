<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\BusinessApiException;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskException;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Security\GuestApiTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Proxies public page rendering to the database-owning business API runtime. */
final readonly class PublicPageApiProxy
{
	public function __construct(
		private BusinessApiClient $api,
		private ApiSessionTokenProvider $sessionTokens,
		private FiberTaskRunner $tasks,
		private GuestApiTokenManager $guestTokens,
	) {
	}

	public function home(Request $request): Response
	{
		return $this->render($request, '/api/public/render/home');
	}

	public function page(Request $request, string $slug): Response
	{
		return $this->render($request, '/api/public/render/pages/'.rawurlencode($slug));
	}

	private function render(Request $request, string $path): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
		];

		try {
			$data = $this->tasks->run([
				'page' => function (FiberHttpClient $http) use ($path, $guestToken, $headers, $request, $siteHost): array {
					$token = $this->sessionTokens->tokenFor($http, $request, $siteHost);
					return $this->api->requestAsync(
						$http,
						'GET',
						$path,
						$token ?? $guestToken,
						query: $request->query->all(),
						headers: $headers,
					);
				},
			], 5.0)['page'];
		} catch (FiberTaskException $exception) {
			$failure = $exception;
			while (null !== ($failure = $failure->getPrevious())) {
				if ($failure instanceof BusinessApiException && in_array($failure->statusCode, [401, 403, 404], true)) {
					return new Response('', $failure->statusCode);
				}
			}

			return new Response('', Response::HTTP_BAD_GATEWAY);
		} catch (BusinessApiException $exception) {
			return new Response('', in_array($exception->statusCode, [401, 403, 404], true) ? $exception->statusCode : Response::HTTP_BAD_GATEWAY);
		} catch (\Throwable) {
			return new Response('', Response::HTTP_BAD_GATEWAY);
		}

		$html = $data['html'] ?? null;
		if (!is_string($html)) {
			return new Response('', Response::HTTP_BAD_GATEWAY);
		}
		$html = $this->rewriteAssetUrls($html, $siteHost);

		return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
	}

	private function rewriteAssetUrls(string $html, string $siteHost): string
	{
		$assetBase = $this->api->getBaseUrl().'/api/public/sites/'.rawurlencode($siteHost).'/assets/';
		$html = preg_replace_callback(
			'~(?<prefix>["\'=(\s])/assets/([a-f0-9]{64})/([a-z][a-z0-9_-]{0,63})~i',
			static function (array $match) use ($assetBase): string {
				return $match['prefix'].$assetBase.$match[2].'/'.$match[3];
			},
			$html,
		) ?? $html;
		$html = preg_replace_callback(
			'~(?<prefix>["\'=(\s])(/api/forms/[a-z0-9_./-]+(?:\?[^"\'\s>]*)?)~i',
			function (array $match): string {
				return $match['prefix'].$this->api->getBaseUrl().$match[2];
			},
			$html,
		) ?? $html;

		return preg_replace_callback('~<(?:img|video)\b[^>]*>~i', static function (array $match) use ($assetBase): string {
			$tag = $match[0];
			if (!str_contains($tag, $assetBase) || 1 === preg_match('/\bcrossorigin\s*=/i', $tag)) {
				return $tag;
			}

			return substr($tag, 0, -1).' crossorigin="use-credentials">';
		}, $html) ?? $html;
	}

}
