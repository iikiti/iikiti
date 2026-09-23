<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed;

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
				return $this->oembed($sanitized, $oembedTemplate, $host, $options);
			}
		}

		return $this->linkCard($url);
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
