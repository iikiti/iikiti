<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Embed;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\GenericVideoProvider;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\VimeoProvider;
use iikiti\CMS\Web\BlockEditor\Embed\Provider\YouTubeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
}
