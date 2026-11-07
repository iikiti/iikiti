<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed;

use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use Closure;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves a URL into embed HTML using allowlisted providers, with caching.
 *
 * Provider selection is deterministic (priority order); unknown / disallowed
 * URLs fall back to a safe link card (no raw HTML is ever injected from an
 * untrusted provider).
 */
final class EmbedResolver
{
	private const MAX_OEMBED_RESPONSE_BYTES = 1_048_576;

	private const HOSTS = [
		// host pattern => oEmbed URL template (%u = url-encoded)
		'https://publish.twitter.com/oembed?url=%s' => 'twitter.com',
		'https://embed.bsky.app/oembed?url=%s' => 'bsky.app',
		'https://www.reddit.com/oembed?url=%s' => 'reddit.com',
	];

	/**
	 * @param iterable<EmbedProviderInterface> $providers Priority-ordered providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.embed_provider')]
		private readonly iterable $providers,
		private readonly HttpClientInterface $httpClient,
		private readonly CacheItemPoolInterface $cache,
		private readonly ?FiberTaskRunner $fiberTaskRunner = null,
	) {
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function resolve(string $url, array $options = []): string
	{
		$sanitized = $this->sanitizeUrl($url);
		if ('' === $sanitized) {
			return $this->linkCard($url, 'Invalid URL');
		}

		foreach ($this->providers as $provider) {
			if ($provider->supports($sanitized)) {
				return $provider->render($sanitized, $options);
			}
		}

		// oEmbed-backed social providers.
		$host = parse_url($sanitized, PHP_URL_HOST) ?? '';
		foreach (self::HOSTS as $oembedTemplate => $knownHost) {
			if ($this->hostMatches($host, $knownHost)) {
				if (null !== $this->fiberTaskRunner && [] === $options) {
					return $this->prefetchOembeds([$sanitized], $this->fiberTaskRunner)[$sanitized] ?? $this->linkCard($url);
				}

				return $this->oembed($sanitized, $oembedTemplate, $host, $options);
			}
		}

		return $this->linkCard($url);
	}

	/**
	 * Prefetches allowlisted oEmbed blocks concurrently. Only fixed provider
	 * endpoints are requested; redirects and oversized or unrelated embed markup
	 * are rejected before output.
	 *
	 * @param list<string> $urls
	 * @return array<string,string> HTML keyed by the original URL
	 */
	public function prefetchOembeds(array $urls, FiberTaskRunner $tasks): array
	{
		$prefetch = $this->prepareOembeds($urls);
		if ([] === $prefetch['operations']) {
			return $prefetch['resolved'];
		}

		return $this->completeOembeds($prefetch, $tasks->run($prefetch['operations']));
	}

	/**
	 * Starts known oEmbed requests, runs local fragment rendering while they are
	 * outstanding, and returns DOM placeholder tokens mapped to their final HTML.
	 *
	 * @param list<string> $urls
	 * @param Closure(array<string,string>):mixed $render
	 * @return array<string,string> data-iikiti-async-embed token => HTML
	 */
	public function renderWhilePrefetchingOembeds(array $urls, FiberTaskRunner $tasks, Closure $render): array
	{
		$prefetch = $this->prepareOembeds($urls);
		$embedValues = $prefetch['resolved'];
		$tokens = [];
		foreach ($prefetch['pending'] as $url => $_entry) {
			$token = bin2hex(random_bytes(16));
			$tokens[$url] = $token;
			$embedValues[$url] = sprintf('<template data-iikiti-async-embed="%s"></template>', $token);
		}

		$execution = $tasks->runDuring(
			$prefetch['operations'],
			static fn (): mixed => $render($embedValues),
		);
		$resolved = $this->completeOembeds($prefetch, $execution['tasks']);
		$deferred = [];
		foreach ($tokens as $url => $token) {
			$deferred[$token] = $resolved[$url] ?? $this->linkCard($url);
		}

		return $deferred;
	}

	/**
	 * @param list<string> $urls
	 * @return array{
	 *   resolved:array<string,string>,
	 *   pending:array<string,array{item:CacheItemInterface,host:string}>,
	 *   operations:array<string,Closure(FiberHttpClient):?string>
	 * }
	 */
	private function prepareOembeds(array $urls): array
	{
		$resolved = [];
		$pending = [];
		$operations = [];
		foreach (array_slice(array_values(array_unique($urls)), 0, 32) as $url) {
			$sanitized = $this->sanitizeUrl($url);
			if ('' === $sanitized) {
				continue;
			}

			$handledByProvider = false;
			foreach ($this->providers as $provider) {
				if ($provider->supports($sanitized)) {
					$handledByProvider = true;
					break;
				}
			}
			if ($handledByProvider) {
				continue;
			}

			$host = (string) (parse_url($sanitized, PHP_URL_HOST) ?? '');
			$oembedTemplate = $this->oembedTemplateForHost($host);
			if (null === $oembedTemplate) {
				continue;
			}

			$key = 'iikiti_embed_oembed:'.md5($sanitized.serialize([]));
			$item = $this->cache->getItem($key);
			if ($item->isHit()) {
				$resolved[$url] = (string) $item->get();
				continue;
			}

			$pending[$url] = ['item' => $item, 'host' => $host];
			$endpoint = sprintf($oembedTemplate, urlencode($sanitized));
			$operations[$url] = static function (FiberHttpClient $http) use ($endpoint): ?string {
				try {
					return $http->request('GET', $endpoint, [
						'headers' => ['User-Agent' => 'iikiti/1.0 (+https://iikiti.org)'],
						'max_redirects' => 0,
						'on_progress' => static function (int $downloadedBytes, int $downloadSize, array $info): void {
							if ($downloadedBytes > self::MAX_OEMBED_RESPONSE_BYTES) {
								throw new \RuntimeException('The oEmbed response exceeded the size limit.');
							}
						},
					])->getContent();
				} catch (\Throwable) {
					return null;
				}
			};
		}

		return ['resolved' => $resolved, 'pending' => $pending, 'operations' => $operations];
	}

	/**
	 * @param array{
	 *   resolved:array<string,string>,
	 *   pending:array<string,array{item:CacheItemInterface,host:string}>,
	 *   operations:array<string,Closure(FiberHttpClient):?string>
	 * } $prefetch
	 * @param array<string,mixed> $bodies
	 * @return array<string,string>
	 */
	private function completeOembeds(array $prefetch, array $bodies): array
	{
		$resolved = $prefetch['resolved'];
		foreach ($prefetch['pending'] as $url => $entry) {
			$body = $bodies[$url] ?? null;
			$data = is_string($body) ? json_decode($body, true) : null;
			$html = is_array($data) && is_string($data['html'] ?? null) &&
				$this->containsAllowedHost($data['html'], $entry['host'])
				? $data['html']
				: '';

			if ('' !== $html) {
				$entry['item']->set($html);
				$entry['item']->expiresAfter(86400);
				$this->cache->save($entry['item']);
			}

			$resolved[$url] = '' !== $html ? $html : $this->linkCard($url);
		}

		return $resolved;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function oembed(string $url, string $oembedTemplate, string $host, array $options): string
	{
		$key = 'iikiti_embed_oembed:'.md5($url.serialize($options));
		$item = $this->cache->getItem($key);
		if ($item->isHit()) {
			return (string) $item->get();
		}

		$html = '';
		try {
			$response = $this->httpClient->request('GET', sprintf($oembedTemplate, urlencode($url)), [
				'timeout' => 5,
				'headers' => ['User-Agent' => 'iikiti/1.0 (+https://iikiti.org)'],
			]);
			$body = (string) $response->getContent();
			$data = json_decode($body, true);

			if (is_array($data) && !empty($data['html']) && $this->containsAllowedHost($data['html'], $host)) {
				$html = (string) $data['html'];
			}
		} catch (\Throwable) {
			$html = '';
		}

		if ('' === $html) {
			return $this->linkCard($url);
		}

		$item->set($html);
		$item->expiresAfter(86400);
		$this->cache->save($item);

		return $html;
	}

	private function sanitizeUrl(string $url): string
	{
		$url = trim($url);
		if (!preg_match('#^https://[a-z0-9.-]+#i', $url)) {
			return '';
		}

		return $url;
	}

	private function linkCard(string $url, ?string $label = null): string
	{
		$text = $label ?? $url;
		$safe = htmlspecialchars($text, ENT_QUOTES);
		$href = htmlspecialchars($url, ENT_QUOTES);

		return '<a href="'.$href.'" class="iikiti-embed--link-card" rel="noopener noreferrer" target="_blank">'.$safe.'</a>';
	}

	private function hostMatches(string $host, string $known): bool
	{
		return str_ends_with($host, $known) || str_ends_with($host, preg_replace('/^www\./', '', $known));
	}

	private function oembedTemplateForHost(string $host): ?string
	{
		foreach (self::HOSTS as $template => $knownHost) {
			if ($this->hostMatches($host, $knownHost)) {
				return $template;
			}
		}

		return null;
	}

	private function containsAllowedHost(string $html, string $host): bool
	{
		// Loose guard: only accept oEmbed HTML that references the requested host.
		$needle = preg_replace('/^www\./', '', $host);

		return
			str_contains($html, $host) ||
			($needle !== $host && str_contains($html, $needle))
		;
	}
}
