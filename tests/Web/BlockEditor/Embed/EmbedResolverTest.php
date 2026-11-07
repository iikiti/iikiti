<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Embed;

use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\GenericVideoProvider;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\VimeoProvider;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\YouTubeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EmbedResolverTest extends TestCase
{
	/**
	 * @param list<array<string,mixed>> $responses
	 */
	private function resolver(array $responses = []): EmbedResolver
	{
		$http = new MockHttpClient(array_map(
			static fn (array $r): MockResponse => new MockResponse(json_encode($r)),
			$responses
		));

		return new EmbedResolver(
			[new YouTubeProvider(), new VimeoProvider(), new GenericVideoProvider()],
			$http,
			new ArrayAdapter(),
		);
	}

	public function testYouTubeWatchUrlRendersIframe(): void
	{
		$html = $this->resolver()->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

		$this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $html);
		$this->assertStringContainsString('iframe', $html);
	}

	public function testYouTubeShortsUrlRendersIframe(): void
	{
		$html = $this->resolver()->resolve('https://youtu.be/abc123_-9XY');

		$this->assertStringContainsString('youtube.com/embed/abc123_-9XY', $html);
	}

	public function testVimeoUrlRendersIframe(): void
	{
		$html = $this->resolver()->resolve('https://vimeo.com/76979871');

		$this->assertStringContainsString('player.vimeo.com/video/76979871', $html);
	}

	public function testDirectMediaUrlRendersVideo(): void
	{
		$html = $this->resolver()->resolve('https://example.com/video.mp4');

		$this->assertStringContainsString('<video', $html);
		$this->assertStringContainsString('https://example.com/video.mp4', $html);
	}

	public function testInvalidUrlFallsBackToLinkCard(): void
	{
		$html = $this->resolver()->resolve('not-a-url');

		$this->assertStringContainsString('iikiti-embed--link-card', $html);
		$this->assertStringContainsString('Invalid URL', $html);
	}

	public function testSocialUrlUsesOembedCache(): void
	{
		$response = [
			'html' => '<blockquote cite="https://twitter.com/example/status/1" class="twitter-tweet">hi</blockquote>',
			'author_url' => 'https://twitter.com/example',
		];
		$resolver = $this->resolver([$response]);

		$html = $resolver->resolve('https://twitter.com/example/status/1');

		$this->assertStringContainsString('<blockquote', $html);
		$this->assertStringContainsString('twitter-tweet', $html);
	}

	public function testPrefetchesAllowlistedOembedsThroughFiberTasks(): void
	{
		$requested = [];
		$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requested): MockResponse {
			$requested[] = [$url, $options['max_redirects'] ?? null];
			$html = str_contains($url, 'publish.twitter.com')
				? '<blockquote cite="https://twitter.com/example/status/1">tweet</blockquote>'
				: '<blockquote cite="https://bsky.app/profile/example/post/2">post</blockquote>';

			return new MockResponse(json_encode(['html' => $html], JSON_THROW_ON_ERROR));
		});
		$cache = new ArrayAdapter();
		$resolver = new EmbedResolver([], $http, $cache);

		$resolved = $resolver->prefetchOembeds([
			'https://twitter.com/example/status/1',
			'https://bsky.app/profile/example/post/2',
		], new FiberTaskRunner($http));

		self::assertCount(2, $requested);
		self::assertSame([0, 0], array_column($requested, 1));
		self::assertStringContainsString('tweet', $resolved['https://twitter.com/example/status/1']);
		self::assertStringContainsString('post', $resolved['https://bsky.app/profile/example/post/2']);
		self::assertSame($resolved['https://twitter.com/example/status/1'], $resolver->resolve('https://twitter.com/example/status/1'));
	}

	public function testDirectSocialEmbedFallbackAlsoUsesNativeFiberRunner(): void
	{
		$http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
			'html' => '<blockquote cite="https://twitter.com/example/status/1">fiber</blockquote>',
		], JSON_THROW_ON_ERROR)));
		$resolver = new EmbedResolver([], $http, new ArrayAdapter(), new FiberTaskRunner($http));

		$html = $resolver->resolve('https://twitter.com/example/status/1');

		self::assertStringContainsString('fiber', $html);
	}
}
