<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Exception;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\BusinessApiException;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Web\BlockEditor\RenderFragmentLimits;
use Psr\Log\LoggerInterface;
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
		private ?AsyncResponseDataCollector $profiler = null,
		private ?LoggerInterface $logger = null,
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
			$apiToken = $this->apiTokenFor($request, $siteHost, $guestToken);
			$mainQuery = $request->query->all();
			$mainQuery['_iikiti_fragment_id'] = '@main';
			$bootstrap = $this->tasks->run([
				'manifest' => function (FiberHttpClient $http) use ($path, $apiToken, $headers, $request): array {
					return $this->api->requestAsync(
						$http,
						'GET',
						$path.'/manifest',
						$apiToken,
						query: $request->query->all(),
						headers: $headers,
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
					);
				},
			], 5.0);
			$rawManifest = $bootstrap['manifest'] ?? null;
			$manifest = $this->normalizeManifest($rawManifest);
			if (null === $manifest) {
				$this->logger?->error('Public page API returned an invalid render manifest.', [
					'manifest_shape' => $this->manifestShape($rawManifest),
				]);

				return new Response('', Response::HTTP_BAD_GATEWAY);
			}

			$renderedFragments = [];
			$mainFragment = $this->normalizeFragmentResult($bootstrap['main'] ?? null, $manifest['editor_mode']);
			if (null === $mainFragment) {
				return new Response('', Response::HTTP_BAD_GATEWAY);
			}
			$mainDescriptor = null;
			foreach ($manifest['fragments'] as $fragment) {
				if ('region' === $fragment['kind'] && 'main' === $fragment['role']) {
					$mainDescriptor = $fragment;
					break;
				}
			}
			if (null !== $mainDescriptor) {
				if (!$mainFragment['found']
					|| $mainFragment['id'] !== $mainDescriptor['id']
					|| $mainFragment['revision'] !== $manifest['revision']) {
					throw new BusinessApiException(Response::HTTP_CONFLICT);
				}
				$renderedFragments[$mainDescriptor['slot']] = $mainFragment;
			}

			$remainingFragments = array_values(array_filter(
				$manifest['fragments'],
				static fn (array $fragment): bool => null === $mainDescriptor || $fragment['slot'] !== $mainDescriptor['slot'],
			));
			foreach (array_chunk($remainingFragments, RenderFragmentLimits::BATCH_SIZE) as $batch) {
				$operations = [];
				foreach ($batch as $fragment) {
					$operations[$fragment['slot']] = function (FiberHttpClient $http) use ($path, $fragment, $manifest, $apiToken, $request, $headers): array {
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
						);
					};
				}

				$renderedFragments += $this->tasks->run($operations, 5.0);
			}

			$document = $this->composeDocument($manifest, $renderedFragments, $siteHost);
			try {
				$this->profiler?->recordComposition('html-dom', count($manifest['fragments']));
			} catch (\Throwable) {
			}

			return new DomHtmlResponse($document, Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
		} catch (\Throwable $exception) {
			$this->logger?->error('Public page API fragment request failed.', [
				'exception_chain' => $this->exceptionChainSummary($exception),
				'server_addr' => $request->server->get('SERVER_ADDR'),
				'server_port' => $request->server->get('SERVER_PORT'),
				'api_host' => parse_url($this->api->getBaseUrl(), PHP_URL_HOST),
			]);
			return $this->failureResponse($exception);
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

	private function apiTokenFor(Request $request, string $siteHost, string $guestToken): string
	{
		$existingToken = $request->headers->get('X-AUTH-TOKEN');
		if (is_string($existingToken) && '' !== $existingToken) {
			return $existingToken;
		}
		if (!$this->sessionTokens->hasSessionCookie($request)) {
			return $guestToken;
		}

		$token = $this->tasks->run([
			'session-token' => fn (FiberHttpClient $http): ?string => $this->sessionTokens->tokenFor($http, $request, $siteHost),
		], 5.0)['session-token'];

		return is_string($token) && '' !== $token ? $token : $guestToken;
	}

	/** @return array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array{kind:string,id:string,role:string,slot:string,depth:int}>}|null */
	private function normalizeManifest(mixed $manifest): ?array
	{
		if (!is_array($manifest)
			|| !is_string($manifest['html'] ?? null)
			|| !is_bool($manifest['editor_mode'] ?? null)
			|| !is_string($manifest['revision'] ?? null)
			|| 1 !== preg_match('/^[a-f0-9]{64}$/D', $manifest['revision'])
			|| !is_array($manifest['fragments'] ?? null)
			|| !array_is_list($manifest['fragments'])
			|| count($manifest['fragments']) > RenderFragmentLimits::MAX_PER_PAGE) {
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
			if (!is_array($fragment)
				|| !in_array($fragment['kind'] ?? null, ['region', 'shell', 'widget'], true)
				|| !is_string($fragment['id'] ?? null)
				|| '' === $fragment['id']
				|| strlen($fragment['id']) > 128
				|| 1 === preg_match('/[\x00-\x1F\x7F]/', $fragment['id'])
				|| !is_string($fragment['role'] ?? null)
				|| '' === $fragment['role']
				|| !is_string($fragment['slot'] ?? null)
				|| 1 !== preg_match('/^[a-f0-9]{32}$/D', $fragment['slot'])) {
				return null;
			}
			if ('shell' === $fragment['kind'] && 1 !== preg_match('/^[1-9][0-9]{0,18}$/D', $fragment['id'])) {
				return null;
			}
			$depth = $fragment['depth'] ?? 0;
			if (!is_int($depth)
				|| ('widget' === $fragment['kind'] && ($depth < 1 || $depth >= RenderFragmentLimits::MAX_PER_PAGE))
				|| ('widget' !== $fragment['kind'] && 0 !== $depth)
				|| ('widget' === $fragment['kind'] && 1 !== preg_match('/^[a-f0-9]{64}$/D', $fragment['id']))) {
				return null;
			}

			$identifier = $fragment['kind'].':'.$fragment['id'];
			if (isset($slots[$fragment['slot']]) || isset($identifiers[$identifier])) {
				return null;
			}
			$slots[$fragment['slot']] = true;
			$identifiers[$identifier] = true;
			$fragments[] = [
				'kind' => $fragment['kind'],
				'id' => $fragment['id'],
				'role' => $fragment['role'],
				'slot' => $fragment['slot'],
				'depth' => $depth,
			];
		}
		usort($fragments, static fn (array $left, array $right): int => $left['depth'] <=> $right['depth']);

		return [
			'html' => $manifest['html'],
			'styles' => $styles,
			'editor_mode' => $manifest['editor_mode'],
			'revision' => $manifest['revision'],
			'fragments' => $fragments,
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
		if (!is_string($fragment['id'] ?? null)
			|| '' === $fragment['id']
			|| !is_string($fragment['revision'] ?? null)
			|| 1 !== preg_match('/^[a-f0-9]{64}$/D', $fragment['revision'])
			|| !is_string($fragment['html'] ?? null)) {
			return null;
		}
		$styles = $this->normalizeStyles($fragment['styles'] ?? null, $editorMode);
		if (null === $styles) {
			return null;
		}

		return ['found' => true, 'id' => $fragment['id'], 'revision' => $fragment['revision'], 'html' => $fragment['html'], 'styles' => $styles];
	}

	/** @param array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array{kind:string,id:string,role:string,slot:string,depth:int}>} $manifest
	 *  @param array<string,mixed> $renderedFragments
	 */
	private function composeDocument(array $manifest, array $renderedFragments, string $siteHost): \Dom\HTMLDocument
	{
		$composer = new HtmlFragmentComposer($this->rewriteAssetUrls($manifest['html'], $siteHost));
		$styleFragments = $manifest['styles'];
		foreach ($manifest['fragments'] as $fragment) {
			$result = $renderedFragments[$fragment['slot']] ?? null;
			if (!is_array($result)
				|| !is_string($result['html'] ?? null)
				|| true !== ($result['found'] ?? false)
				|| ($result['id'] ?? null) !== $fragment['id']) {
				throw new \UnexpectedValueException('The API returned an invalid page fragment.');
			}
			if (($result['revision'] ?? null) !== $manifest['revision']) {
				throw new BusinessApiException(Response::HTTP_CONFLICT);
			}
			$fragmentStyles = $this->normalizeStyles($result['styles'] ?? null, $manifest['editor_mode']);
			if (null === $fragmentStyles) {
				throw new \UnexpectedValueException('The API returned invalid page fragment styles.');
			}

			$slotAttribute = match ($fragment['kind']) {
				'region' => 'data-iikiti-region-slot',
				'shell' => 'data-iikiti-shell-slot',
				'widget' => 'data-iikiti-widget-slot',
				default => throw new \UnexpectedValueException('Unsupported page fragment kind.'),
			};
			$selector = sprintf('template[%s="%s"]', $slotAttribute, $fragment['slot']);
			$composer->insertIntoPlaceholder($selector, $this->rewriteAssetUrls($result['html'], $siteHost));
			foreach ($fragmentStyles as $style) {
				$styleFragments[] = $style;
			}
	}
		$styles = $this->renderStyles($styleFragments, $manifest['editor_mode'], $siteHost);
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="custom-css"]', $styles);

		return $composer->document();
	}

	/** @return list<array{id:?string,css:string}>|null */
	private function normalizeStyles(mixed $styles, bool $editorMode): ?array
	{
		if (!is_array($styles) || !array_is_list($styles) || count($styles) > 256) {
			return null;
		}

		$normalized = [];
		foreach ($styles as $style) {
			if (!is_array($style) || !array_key_exists('id', $style) || !is_string($style['css'] ?? null)) {
				return null;
			}
			$id = $style['id'];
			if (($editorMode && !is_string($id)) || (!$editorMode && null !== $id)
				|| (is_string($id) && ('' === $id || strlen($id) > 128 || 1 === preg_match('/[\x00-\x1F\x7F]/', $id)))) {
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
			$css = $this->rewriteAssetUrls($style['css'], $siteHost);
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
			if ($failure instanceof BusinessApiException && in_array($failure->statusCode, [401, 403, 404, 409], true)) {
				return new Response('', $failure->statusCode);
			}
		}

		return new Response('', Response::HTTP_BAD_GATEWAY);
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
