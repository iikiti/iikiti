<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\BusinessApiException;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Async\Promise;
use iikiti\CMS\Filters\InlineAssetMinificationOptions;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Registry\RouteContextClassifier;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Web\Template\HttpErrorPageRenderer;
use Jimbo2150\CssIndexedDom\IndexedHtmlDocument;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Proxies public page rendering to the database-owning business API runtime. */
final readonly class PublicPageApiProxy
{
	private const MAX_PROVIDER_BYTES_PER_PAGE = 8_388_608;

	private readonly RouteContextClassifier $routeClassifier;

	public function __construct(
		private BusinessApiClient $api,
		private ApiSessionTokenProvider $sessionTokens,
		private FiberTaskRunner $tasks,
		private GuestApiTokenManager $guestTokens,
		private ?AsyncResponseDataCollector $profiler = null,
		private ?LoggerInterface $logger = null,
		private ?HttpErrorPageRenderer $errorPages = null,
		?RouteContextClassifier $routeClassifier = null,
	) {
		$this->routeClassifier = $routeClassifier ?? new RouteContextClassifier();
	}

	public function home(Request $request): Response
	{
		return $this->render($request, '/api/public/render/home');
	}

	public function page(Request $request, string $slug): Response
	{
		return $this->render($request, '/api/public/render/pages/'.rawurlencode($slug));
	}

	public function proxyJsonDocument(Request $request, string $apiPath, string $expectedContentType): Response
	{
		return $this->proxyDiscoveryDocument($request, $apiPath, $expectedContentType);
	}

	public function fragment(Request $request, string $provider): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
			'X-Forwarded-Proto' => $request->getScheme(),
			'X-SITE-LOCALE' => $request->getLocale(),
		];
		$params = $request->query->get('params', '{}');
		if (strlen($params) > 16_384) {
			return new JsonResponse(['message' => 'Fragment parameters are invalid or too large.'], Response::HTTP_BAD_REQUEST);
		}

		return $this->executeFragmentRequest($request, $provider, $siteHost, $guestToken, $headers, $params, forceTokenRefresh: false);
	}

	/** @param array<string,string> $headers */
	private function executeFragmentRequest(
		Request $request,
		string $provider,
		string $siteHost,
		string $guestToken,
		array $headers,
		string $params,
		bool $forceTokenRefresh,
	): Response {
		$tokenInfo = ['token' => $guestToken, 'from_session_cache' => false];
		try {
			$tokenInfo = $this->resolveApiToken($request, $siteHost, $guestToken, $forceTokenRefresh);
			$result = $this->api->request(
				'GET',
				'/api/public/render/providers/'.rawurlencode($provider),
				$tokenInfo['token'],
				query: ['params' => $params],
				headers: $headers,
			);
			if (!is_string($result['html'] ?? null) || !is_array($result['styles'] ?? null)) {
				return new JsonResponse(['message' => 'The fragment API returned an invalid response.'], Response::HTTP_BAD_GATEWAY);
			}

			return new JsonResponse(['html' => $result['html'], 'styles' => $result['styles']], Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
		} catch (BusinessApiException $exception) {
			if (Response::HTTP_UNAUTHORIZED === $exception->statusCode && $tokenInfo['from_session_cache'] && !$forceTokenRefresh) {
				$this->sessionTokens->invalidateCachedToken($request, $siteHost);

				return $this->executeFragmentRequest($request, $provider, $siteHost, $guestToken, $headers, $params, forceTokenRefresh: true);
			}
			$status = in_array($exception->statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true) ?
				$exception->statusCode :
				(Response::HTTP_CONFLICT === $exception->statusCode ? Response::HTTP_CONFLICT : Response::HTTP_BAD_GATEWAY);

			return new JsonResponse(['message' => 'The requested fragment could not be rendered.'], $status, ['Cache-Control' => 'private, no-store']);
		} catch (\Throwable $exception) {
			$this->logger?->error('Public fragment API request failed.', ['provider' => $provider, 'exception' => $exception]);

			return new JsonResponse(['message' => 'The requested fragment could not be rendered.'], Response::HTTP_BAD_GATEWAY, ['Cache-Control' => 'private, no-store']);
		}
	}

	private function render(Request $request, string $path, bool $forceTokenRefresh = false): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
			'X-Forwarded-Proto' => $request->getScheme(),
			'X-SITE-LOCALE' => $request->getLocale(),
		];

		$tokenInfo = ['token' => $guestToken, 'from_session_cache' => false];
		try {
			$tokenInfo = $this->resolveApiToken($request, $siteHost, $guestToken, $forceTokenRefresh);
			$apiToken = $tokenInfo['token'];
			$mainQuery = $request->query->all();
			$mainQuery['_iikiti_fragment_id'] = '@main';
			$bootstrap = $this->tasks->runUntil([
				'manifest' => function (FiberHttpClient $http) use ($path, $apiToken, $headers, $request): array {
					return $this->api->requestAsync(
						$http,
						'GET',
						$path.'/manifest',
						$apiToken,
						query: $request->query->all(),
						headers: $headers,
						collectDoctrineQueries: true,
					);
				},
				'main' => function (FiberHttpClient $http) use ($path, $apiToken, $headers, $mainQuery): array {
					return $this->api->requestAsync(
						$http,
						'GET',
						$path.'/fragments/region',
						$apiToken,
						query: $mainQuery,
						headers: $headers,
						collectDoctrineQueries: true,
					);
				},
			], static fn (array $_results, array $errors): bool => [] !== $errors, 5.0);
			$rawManifest = $bootstrap['manifest'] ?? null;
			$manifest = $this->normalizeManifest($rawManifest);
			if (null === $manifest) {
				$this->logger?->error('Public page API returned an invalid render manifest.', [
					'manifest_shape' => $this->manifestShape($rawManifest),
				]);

				return new Response('', Response::HTTP_BAD_GATEWAY);
			}
			$request->attributes->set(
				InlineAssetMinificationOptions::REQUEST_ATTRIBUTE,
				$manifest['inline_asset_minification'],
			);
			if ([] !== $manifest['filter_options']) {
				$request->attributes->set('_iikiti_response_filter_options', $manifest['filter_options']);
			}

			$renderedFragments = [];
			$mainDescriptor = null;
			foreach ($manifest['fragments'] as $fragment) {
				if ('region' === $fragment['kind'] && 'main' === $fragment['role']) {
					$mainDescriptor = $fragment;
					break;
				}
			}
			if (null !== $mainDescriptor) {
				$mainFragment = $this->normalizeFragmentResult($bootstrap['main'] ?? null, $manifest['editor_mode']);
				if (null === $mainFragment) {
					return new Response('', Response::HTTP_BAD_GATEWAY);
				}
				if (!$mainFragment['found'] ||
					$mainFragment['id'] !== $mainDescriptor['id'] ||
					$mainFragment['revision'] !== $manifest['revision']) {
					throw new BusinessApiException(Response::HTTP_CONFLICT);
				}
				$renderedFragments[$mainDescriptor['slot']] = $mainFragment;
			}

			$remainingFragments = array_values(array_filter(
				$manifest['fragments'],
				static fn (array $fragment): bool => null === $mainDescriptor || $fragment['slot'] !== $mainDescriptor['slot'],
			));
			$renderedFragments = $this->fetchRemainingFragments(
				$path,
				$apiToken,
				$headers,
				$request,
				$manifest,
				$remainingFragments,
				$renderedFragments,
			);

			$document = $this->composeDocument($manifest, $renderedFragments, $siteHost);
			try {
				$this->profiler?->recordComposition('html-dom', count($manifest['fragments']));
			} catch (\Throwable) {
			}

			return new DomHtmlResponse($document, Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
		} catch (\Throwable $exception) {
			if ($tokenInfo['from_session_cache'] && !$forceTokenRefresh && $this->isUnauthorizedException($exception)) {
				$this->sessionTokens->invalidateCachedToken($request, $siteHost);

				return $this->render($request, $path, forceTokenRefresh: true);
			}
			$this->logger?->error('Public page API fragment request failed.', [
				'exception_chain' => $this->exceptionChainSummary($exception),
				'server_addr' => $request->server->get('SERVER_ADDR'),
				'server_port' => $request->server->get('SERVER_PORT'),
				'api_host' => parse_url($this->api->getBaseUrl(), PHP_URL_HOST),
			]);

			return $this->failureResponse($exception);
		}
	}

	/**
	 * Fetches remaining region, shell, widget, and provider fragments concurrently
	 * via a bounded sliding-window Fiber pool, unblocking dependent providers as
	 * soon as their prerequisites complete and cancelling siblings on first error.
	 *
	 * @param array<string,string> $headers
	 * @param array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array<string,mixed>>,inline_asset_minification:array{css_minify:bool,js_minify:bool,js_uglify:bool},filter_options:array<string,mixed>} $manifest
	 * @param list<array<string,mixed>> $remainingFragments
	 * @param array<string,mixed> $renderedFragments
	 * @return array<string,mixed>
	 */
	private function fetchRemainingFragments(
		string $path,
		string $apiToken,
		array $headers,
		Request $request,
		array $manifest,
		array $remainingFragments,
		array $renderedFragments,
	): array {
		if ([] === $remainingFragments) {
			return $renderedFragments;
		}

		$state = new \stdClass();
		$state->pending = $remainingFragments;
		$state->completedProviders = [];
		$state->rendered = $renderedFragments;
		$state->providerBytes = 0;
		$state->inFlight = 0;
		$state->aborted = false;
		$state->progress = Promise::withResolvers();

		$notify = static function () use ($state): void {
			$current = $state->progress;
			$state->progress = Promise::withResolvers();
			($current->resolve)(null);
		};
		$claimNext = static function () use ($state): ?array {
			foreach ($state->pending as $index => $fragment) {
				if ('provider' !== $fragment['kind']
					|| [] === array_diff($fragment['dependsOn'], array_keys($state->completedProviders))) {
					array_splice($state->pending, $index, 1);

					return $fragment;
				}
			}

			return null;
		};

		$workerCount = min(RenderFragmentLimits::BATCH_SIZE, count($remainingFragments));
		$workers = [];
		for ($i = 0; $i < $workerCount; ++$i) {
			$workers['worker-'.$i] = Promise::async(function (FiberHttpClient $http) use ($state, $notify, $claimNext, $path, $apiToken, $headers, $request, $manifest): void {
				while (!$state->aborted) {
					$fragment = $claimNext();
					if (null === $fragment) {
						if ([] === $state->pending) {
							return;
						}
						if (0 === $state->inFlight) {
							throw new \UnexpectedValueException('The fragment dependency plan cannot make progress.');
						}
						$state->progress->promise->execute($http);

						continue;
					}

					++$state->inFlight;
					try {
						$raw = $this->requestFragment($http, $path, $fragment, $manifest, $apiToken, $request, $headers);
						$normalized = 'provider' === $fragment['kind'] ?
							$this->normalizeProviderResult($raw, $fragment['id'], $manifest['editor_mode']) :
							$this->normalizeFragmentResult($raw, $manifest['editor_mode']);
						if (null === $normalized) {
							throw new \UnexpectedValueException('The API returned an invalid page fragment.');
						}
						if ('provider' === $fragment['kind']) {
							$state->providerBytes += strlen($normalized['html']);
							foreach ($normalized['styles'] as $style) {
								$state->providerBytes += strlen($style['css']);
							}
							if ($state->providerBytes > self::MAX_PROVIDER_BYTES_PER_PAGE) {
								throw new \UnexpectedValueException('Plugin fragments exceed the page response size limit.');
							}
						}

						$state->rendered[$fragment['slot']] = $normalized;
						if ('provider' === $fragment['kind']) {
							$state->completedProviders[$fragment['key']] = true;
							$notify();
						}
					} catch (\Throwable $exception) {
						$state->aborted = true;
						$notify();
						throw $exception;
					} finally {
						--$state->inFlight;
					}
				}
			});
		}

		$this->tasks->runPromisesUntil(
			$workers,
			static fn (array $_results, array $errors): bool => [] !== $errors,
			5.0,
		);

		return $state->rendered;
	}

	/**
	 * @param array<string,mixed> $fragment
	 * @param array{editor_mode:bool,revision:string} $manifest
	 * @param array<string,string> $headers
	 * @return array<mixed>
	 */
	private function requestFragment(
		FiberHttpClient $http,
		string $path,
		array $fragment,
		array $manifest,
		string $apiToken,
		Request $request,
		array $headers,
	): array {
		if ('provider' === $fragment['kind']) {
			return $this->api->requestAsync(
				$http,
				'GET',
				'/api/public/render/providers/'.rawurlencode($fragment['id']),
				$apiToken,
				query: ['params' => json_encode((object) $fragment['params'], JSON_THROW_ON_ERROR, 16)],
				headers: $headers,
				collectDoctrineQueries: true,
			);
		}

		$query = $request->query->all();
		$query['_iikiti_fragment_id'] = $fragment['id'];
		$query['_iikiti_render_revision'] = $manifest['revision'];

		return $this->api->requestAsync(
			$http,
			'GET',
			$path.'/fragments/'.$fragment['kind'],
			$apiToken,
			query: $query,
			headers: $headers,
			collectDoctrineQueries: true,
		);
	}

	private function proxyDiscoveryDocument(Request $request, string $path, string $expectedContentType): Response
	{
		$siteHost = strtolower($request->getHost());
		$guestToken = $this->guestTokens->issue($siteHost);
		$headers = [
			'X-SITE-HOST' => $siteHost,
			'X-SITE-SCHEME' => $request->getScheme(),
			'X-Forwarded-Proto' => $request->getScheme(),
		];

		try {
			$result = $this->api->request(
				'GET',
				$path,
				$guestToken,
				headers: $headers,
			);
			if (!is_string($result['body'] ?? null) || ($result['contentType'] ?? null) !== $expectedContentType) {
				return new JsonResponse(['message' => 'The public discovery API returned an invalid response.'], Response::HTTP_BAD_GATEWAY, ['Cache-Control' => 'no-store']);
			}

			return new Response($result['body'], Response::HTTP_OK, [
				'Content-Type' => $expectedContentType,
				'Cache-Control' => 'public, max-age=300',
			]);
		} catch (BusinessApiException $exception) {
			$status = in_array($exception->statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true) ?
				$exception->statusCode :
				Response::HTTP_BAD_GATEWAY;

			return new JsonResponse(['message' => 'The public discovery document could not be loaded.'], $status, ['Cache-Control' => 'no-store']);
		} catch (\Throwable $exception) {
			$this->logger?->error('Public discovery API request failed.', ['path' => $path, 'exception' => $exception]);

			return new JsonResponse(['message' => 'The public discovery document could not be loaded.'], Response::HTTP_BAD_GATEWAY, ['Cache-Control' => 'no-store']);
		}
	}

	/** @return array<string,string> */
	private function manifestShape(mixed $manifest): array
	{
		if (!is_array($manifest)) {
			return ['type' => get_debug_type($manifest)];
		}

		$shape = [];
		foreach ($manifest as $key => $value) {
			$shape[(string) $key] = get_debug_type($value);
		}

		return $shape;
	}

	/** @return list<array{class:string,message:string}> */
	private function exceptionChainSummary(\Throwable $exception): array
	{
		$chain = [];
		for ($failure = $exception; null !== $failure; $failure = $failure->getPrevious()) {
			$message = preg_replace('/(https?:\/\/[^?\s]+)\?[^\s]+/', '$1?[redacted]', $failure->getMessage()) ?? '';
			$chain[] = ['class' => $failure::class, 'message' => substr($message, 0, 300)];
		}

		return $chain;
	}

	/** @return array{token:string,from_session_cache:bool} */
	private function resolveApiToken(Request $request, string $siteHost, string $guestToken, bool $forceRefresh = false): array
	{
		$existingToken = $request->headers->get('X-AUTH-TOKEN');
		if (is_string($existingToken) && '' !== $existingToken) {
			return ['token' => $existingToken, 'from_session_cache' => false];
		}
		if (!$this->sessionTokens->hasSessionCookie($request)) {
			return ['token' => $guestToken, 'from_session_cache' => false];
		}
		if (!$forceRefresh) {
			$cachedToken = $this->sessionTokens->cachedTokenFor($request, $siteHost);
			if (null !== $cachedToken) {
				return ['token' => $cachedToken, 'from_session_cache' => true];
			}
		}

		$token = $this->tasks->run([
			'session-token' => fn (FiberHttpClient $http): ?string => $this->sessionTokens->tokenFor($http, $request, $siteHost, collectDoctrineQueries: true),
		], 5.0)['session-token'];

		return [
			'token' => is_string($token) && '' !== $token ? $token : $guestToken,
			'from_session_cache' => false,
		];
	}

	private function isUnauthorizedException(\Throwable $exception): bool
	{
		for ($failure = $exception; null !== $failure; $failure = $failure->getPrevious()) {
			if ($failure instanceof BusinessApiException && Response::HTTP_UNAUTHORIZED === $failure->statusCode) {
				return true;
			}
		}

		return false;
	}

	/** @return array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array<string,mixed>>,inline_asset_minification:array{css_minify:bool,js_minify:bool,js_uglify:bool},filter_options:array<string,mixed>} */
	private function normalizeManifest(mixed $manifest): ?array
	{
		$filterOptions = is_array($manifest) && is_array($manifest['filter_options'] ?? null) ?
			$manifest['filter_options'] :
			[];
		$rawInlineAssetMinification = is_array($manifest) ?
			($manifest['inline_asset_minification'] ?? ($filterOptions['inline_asset_minification'] ?? null)) :
			null;
		$inlineAssetOptions = InlineAssetMinificationOptions::fromArray($rawInlineAssetMinification);
		if (!is_array($manifest) ||
			!is_string($manifest['html'] ?? null) ||
			!is_bool($manifest['editor_mode'] ?? null) ||
			null === $inlineAssetOptions ||
			!is_string($manifest['revision'] ?? null) ||
			1 !== preg_match('/^[a-f0-9]{64}$/D', $manifest['revision']) ||
			!is_array($manifest['fragments'] ?? null) ||
			!array_is_list($manifest['fragments']) ||
			count($manifest['fragments']) > RenderFragmentLimits::MAX_PER_PAGE) {
			return null;
		}
		$styles = $this->normalizeStyles($manifest['styles'], $manifest['editor_mode']);
		if (null === $styles) {
			return null;
		}

		$fragments = [];
		$slots = [];
		$identifiers = [];
		foreach ($manifest['fragments'] as $fragment) {
			if (!is_array($fragment) ||
				!in_array($fragment['kind'] ?? null, ['region', 'shell', 'widget', 'provider'], true) ||
				!is_string($fragment['id'] ?? null) ||
				'' === $fragment['id'] ||
				strlen($fragment['id']) > 128 ||
				1 === preg_match('/[\x00-\x1F\x7F]/', $fragment['id']) ||
				!is_string($fragment['role'] ?? null) ||
				'' === $fragment['role'] ||
				('provider' === $fragment['kind'] && 'provider' !== $fragment['role']) ||
				!is_string($fragment['slot'] ?? null) ||
				1 !== preg_match('/^[a-f0-9]{32}$/D', $fragment['slot'])) {
				return null;
			}
			if ('shell' === $fragment['kind'] && 1 !== preg_match('/^[1-9][0-9]{0,18}$/D', $fragment['id'])) {
				return null;
			}
			$depth = $fragment['depth'] ?? 0;
			if (!is_int($depth) ||
				('widget' === $fragment['kind'] && ($depth < 1 || $depth >= RenderFragmentLimits::MAX_PER_PAGE)) ||
				(!in_array($fragment['kind'], ['widget', 'provider'], true) && 0 !== $depth) ||
				('widget' === $fragment['kind'] && 1 !== preg_match('/^[a-f0-9]{64}$/D', $fragment['id'])) ||
				('provider' === $fragment['kind'] && (0 !== $depth || 1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.[a-z][a-z0-9_.-]{0,63}$/D', $fragment['id'])))) {
				return null;
			}

			$identifier = 'provider' === $fragment['kind'] ?
				$fragment['kind'].':'.(string) ($fragment['key'] ?? '') :
				$fragment['kind'].':'.$fragment['id'];
			if (isset($slots[$fragment['slot']]) || isset($identifiers[$identifier])) {
				return null;
			}
			$slots[$fragment['slot']] = true;
			$identifiers[$identifier] = true;
			$normalizedFragment = [
				'kind' => $fragment['kind'],
				'id' => $fragment['id'],
				'role' => $fragment['role'],
				'slot' => $fragment['slot'],
				'depth' => $depth,
			];
			if ('provider' === $fragment['kind']) {
				$key = $fragment['key'] ?? null;
				$dependencies = $fragment['dependsOn'] ?? null;
				$params = $fragment['params'] ?? null;
				if (!is_string($key) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) ||
					!is_array($dependencies) || !array_is_list($dependencies) ||
					!is_array($params) || ([] !== $params && array_is_list($params)) ||
					16_384 < strlen(json_encode($params, JSON_THROW_ON_ERROR, 16))) {
					return null;
				}
				foreach ($dependencies as $dependency) {
					if (!is_string($dependency) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $dependency)) {
						return null;
					}
				}
				$normalizedFragment['key'] = $key;
				$normalizedFragment['dependsOn'] = array_values(array_unique($dependencies));
				$normalizedFragment['params'] = $params;
			}
			$fragments[] = $normalizedFragment;
		}
		$providerKeys = [];
		foreach ($fragments as $fragment) {
			if ('provider' === $fragment['kind']) {
				if (isset($providerKeys[$fragment['key']])) {
					return null;
				}
				$providerKeys[$fragment['key']] = $fragment;
			}
		}
		foreach ($providerKeys as $fragment) {
			foreach ($fragment['dependsOn'] as $dependency) {
				if (!isset($providerKeys[$dependency]) || $dependency === $fragment['key']) {
					return null;
				}
			}
		}
		$visited = [];
		$visiting = [];
		$visit = function (string $key) use (&$visit, &$visited, &$visiting, $providerKeys): bool {
			if (isset($visiting[$key])) {
				return false;
			}
			if (isset($visited[$key])) {
				return true;
			}
			$visiting[$key] = true;
			foreach ($providerKeys[$key]['dependsOn'] as $dependency) {
				if (!$visit($dependency)) {
					return false;
				}
			}
			unset($visiting[$key]);
			$visited[$key] = true;

			return true;
		};
		foreach (array_keys($providerKeys) as $key) {
			if (!$visit($key)) {
				return null;
			}
		}
		usort($fragments, static fn (array $left, array $right): int => $left['depth'] <=> $right['depth']);

		return [
			'html' => $manifest['html'],
			'styles' => $styles,
			'editor_mode' => $manifest['editor_mode'],
			'revision' => $manifest['revision'],
			'fragments' => $fragments,
			'inline_asset_minification' => $inlineAssetOptions->toArray(),
			'filter_options' => $filterOptions,
		];
	}

	/** @return array{found:bool,id:?string,revision:?string,html:string,styles:list<array{id:?string,css:string}>}|null */
	private function normalizeFragmentResult(mixed $fragment, bool $editorMode): ?array
	{
		if (!is_array($fragment) || !is_bool($fragment['found'] ?? null)) {
			return null;
		}
		if (!$fragment['found']) {
			return ['found' => false, 'id' => null, 'revision' => null, 'html' => '', 'styles' => []];
		}
		if (!is_string($fragment['id'] ?? null) ||
			'' === $fragment['id'] ||
			!is_string($fragment['revision'] ?? null) ||
			1 !== preg_match('/^[a-f0-9]{64}$/D', $fragment['revision']) ||
			!is_string($fragment['html'] ?? null)) {
			return null;
		}
		$styles = $this->normalizeStyles($fragment['styles'] ?? null, $editorMode);
		if (null === $styles) {
			return null;
		}

		return ['found' => true, 'id' => $fragment['id'], 'revision' => $fragment['revision'], 'html' => $fragment['html'], 'styles' => $styles];
	}

	/** @return array{found:true,id:string,revision:null,html:string,styles:list<array{id:?string,css:string}>}|null */
	private function normalizeProviderResult(mixed $fragment, string $provider, bool $editorMode): ?array
	{
		if (!is_array($fragment) || !is_string($fragment['html'] ?? null)) {
			return null;
		}
		$styles = $this->normalizeStyles($fragment['styles'] ?? null, $editorMode, allowPublicStyleIds: true);
		if (null === $styles || strlen($fragment['html']) > \iikiti\CMS\Web\Fragment\FragmentResult::MAX_HTML_BYTES) {
			return null;
		}

		return ['found' => true, 'id' => $provider, 'revision' => null, 'html' => $fragment['html'], 'styles' => $styles];
	}

	/**
	 * @param array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array<string,mixed>>} $manifest
	 * @param array<string,mixed>                                                                                                               $renderedFragments
	 */
	private function composeDocument(array $manifest, array $renderedFragments, string $siteHost): IndexedHtmlDocument
	{
		$composer = new HtmlFragmentComposer($this->rewriteAssetUrls($manifest['html'], $siteHost));
		$styleFragments = $manifest['styles'];
		foreach ($manifest['fragments'] as $fragment) {
			$result = $renderedFragments[$fragment['slot']] ?? null;
			if (!is_array($result) ||
				!is_string($result['html'] ?? null) ||
				true !== ($result['found'] ?? false) ||
				($result['id'] ?? null) !== $fragment['id']) {
				throw new \UnexpectedValueException('The API returned an invalid page fragment.');
			}
			if ('provider' !== $fragment['kind'] && ($result['revision'] ?? null) !== $manifest['revision']) {
				throw new BusinessApiException(Response::HTTP_CONFLICT);
			}
			// Fragment styles were already validated by normalizeFragmentResult()/normalizeProviderResult().
			/** @var list<array{id:?string,css:string}> $fragmentStyles */
			$fragmentStyles = is_array($result['styles'] ?? null) ? $result['styles'] : [];

			$slotAttribute = match ($fragment['kind']) {
				'region' => 'data-iikiti-region-slot',
				'shell' => 'data-iikiti-shell-slot',
				'widget' => 'data-iikiti-widget-slot',
				'provider' => 'data-iikiti-provider-slot',
				default => throw new \UnexpectedValueException('Unsupported page fragment kind.'),
			};
			$selector = sprintf('template[%s="%s"]', $slotAttribute, $fragment['slot']);
			$composer->insertIntoPlaceholder($selector, $this->rewriteAssetUrls($result['html'], $siteHost));
			foreach ($fragmentStyles as $style) {
				$styleFragments[] = $style;
			}
		}
		$styles = $this->renderStyles($styleFragments, $manifest['editor_mode'], $siteHost);
		$composer->insertIntoPlaceholder('template[data-iikiti-fragment="custom-css"]', $styles);
		$document = $composer->indexedDocument();
		$this->applyAssetCrossOrigin($document, $this->assetBaseUrl($siteHost));

		return $document;
	}

	/** @return list<array{id:?string,css:string}>|null */
	private function normalizeStyles(mixed $styles, bool $editorMode, bool $allowPublicStyleIds = false): ?array
	{
		if (!is_array($styles) || !array_is_list($styles) || count($styles) > 256) {
			return null;
		}

		$normalized = [];
		foreach ($styles as $style) {
			if (!is_array($style) || !array_key_exists('id', $style) || !is_string($style['css'] ?? null)) {
				return null;
			}
			if (1 === preg_match('/<\/style/i', $style['css'])) {
				return null;
			}
			$id = $style['id'];
			if ((!$allowPublicStyleIds && $editorMode && !is_string($id)) || (!$editorMode && !$allowPublicStyleIds && null !== $id) ||
				(is_string($id) && ('' === $id || strlen($id) > 128 || 1 === preg_match('/[\x00-\x1F\x7F]/', $id)))) {
				return null;
			}
			$normalized[] = ['id' => $id, 'css' => $style['css']];
		}

		return $normalized;
	}

	/** @param list<array{id:?string,css:string}> $styles */
	private function renderStyles(array $styles, bool $editorMode, string $siteHost): string
	{
		$stylesById = [];
		$seenCss = [];
		foreach ($styles as $style) {
			$css = str_ireplace('</style', '\\3C /style', $this->rewriteAssetUrls($style['css'], $siteHost));
			$key = $editorMode ? (string) $style['id'] : '';
			if (!isset($stylesById[$key])) {
				$stylesById[$key] = [];
			}
			if (isset($seenCss[$key][$css])) {
				continue;
			}
			$seenCss[$key][$css] = true;
			$stylesById[$key][] = $css;
		}

		if (!$editorMode) {
			$css = implode('', $stylesById[''] ?? []);

			return '' === $css ? '' : '<style id="iikiti-custom-css">'.$css.'</style>';
		}

		$html = '';
		foreach ($stylesById as $id => $chunks) {
			$styleId = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html .= '<style id="'.$styleId.'" data-iikiti-custom-css>'.implode('', $chunks).'</style>';
		}

		return $html;
	}

	private function failureResponse(\Throwable $exception): Response
	{
		for ($failure = $exception; null !== $failure; $failure = $failure->getPrevious()) {
			if ($failure instanceof BusinessApiException && $failure->statusCode >= 400 && $failure->statusCode <= 599) {
				return $this->renderError($failure->statusCode, $failure->responseHeaders);
			}
		}

		return $this->renderError(Response::HTTP_BAD_GATEWAY);
	}

	/** @param array<string,string> $headers */
	private function renderError(int $statusCode, array $headers = []): Response
	{
		return $this->errorPages?->render($statusCode, $headers) ?? HttpErrorPageRenderer::fallback($statusCode, $headers);
	}

	private function assetBaseUrl(string $siteHost): string
	{
		return $this->api->getBaseUrl().'/api/public/sites/'.rawurlencode($siteHost).'/assets/';
	}

	private function applyAssetCrossOrigin(IndexedHtmlDocument $document, string $assetBase): void
	{
		foreach ($document->querySelectorAll('img, video') as $element) {
			if ($element->hasAttribute('crossorigin')) {
				continue;
			}
			$src = $element->getAttribute('src') ?? '';
			$srcset = $element->getAttribute('srcset') ?? '';
			$poster = $element->getAttribute('poster') ?? '';
			$matchesAsset = str_contains($src, $assetBase)
				|| str_contains($srcset, $assetBase)
				|| str_contains($poster, $assetBase);
			if (!$matchesAsset && 'VIDEO' === strtoupper($element->nodeName)) {
				foreach ($document->querySelectorAll('source', $element) as $source) {
					if (str_contains($source->getAttribute('src') ?? '', $assetBase)) {
						$matchesAsset = true;
						break;
					}
				}
			}
			if ($matchesAsset) {
				$document->setAttribute($element, 'crossorigin', 'use-credentials');
			}
		}
	}

	private function rewriteAssetUrls(string $html, string $siteHost): string
	{
		$prefixes = $this->routeClassifier->getConsumerUrlRewritePrefixes();
		$hasPrefix = false;
		foreach ($prefixes as $prefix) {
			if (false !== stripos($html, $prefix)) {
				$hasPrefix = true;
				break;
			}
		}
		if (false === stripos($html, '/assets/') && !$hasPrefix) {
			return $html;
		}

		$assetBase = $this->assetBaseUrl($siteHost);
		if (false !== stripos($html, '/assets/')) {
			$html = preg_replace_callback(
				'~(?<prefix>["\'=(\s])/assets/([a-f0-9]{64})/([a-z][a-z0-9_-]{0,63})~i',
				static function (array $match) use ($assetBase): string {
					return $match['prefix'].$assetBase.$match[2].'/'.$match[3];
				},
				$html,
			) ?? $html;
		}
		foreach ($prefixes as $prefix) {
			if (false !== stripos($html, $prefix)) {
				$quotedPrefix = preg_quote(rtrim($prefix, '/').'/', '~');
				$html = preg_replace_callback(
					'~(?<prefix>["\'=(\s])('.$quotedPrefix.'[a-z0-9_./-]+(?:\?[^"\'\s>]*)?)~i',
					function (array $match): string {
						return $match['prefix'].$this->api->getBaseUrl().$match[2];
					},
					$html,
				) ?? $html;
			}
		}

		return $html;
	}
}
