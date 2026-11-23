<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';

final class PageSeoSettingsNormalizerTest extends TestCase
{
	public function testReturnsOnlyPresentSeoFieldsAndKeepsEmptyStringsAsInherited(): void
	{
		$normalizer = new PageSeoSettingsNormalizer();

		$normalized = $normalizer->normalize([
			'iikiti_content_optimization_title' => '  Page title  ',
			'iikiti_content_optimization_description' => '',
			'iikiti_content_optimization_robots_index' => 'noindex',
			'unrelated_setting' => 'preserve me elsewhere',
		]);

		self::assertSame([
			'iikiti_content_optimization_title' => 'Page title',
			'iikiti_content_optimization_description' => '',
			'iikiti_content_optimization_robots_index' => 'noindex',
		], $normalized);
	}

	public function testAcceptsAllSupportedFieldValues(): void
	{
		$normalizer = new PageSeoSettingsNormalizer();
		$settings = [
			'iikiti_content_optimization_title' => 'Title',
			'iikiti_content_optimization_description' => 'Description',
			'iikiti_content_optimization_canonical_url' => '/articles/example?view=full#top',
			'iikiti_content_optimization_robots_index' => 'index',
			'iikiti_content_optimization_robots_follow' => 'follow',
			'iikiti_content_optimization_og_title' => 'Social title',
			'iikiti_content_optimization_og_description' => 'Social description',
			'iikiti_content_optimization_og_type' => 'article',
			'iikiti_content_optimization_og_image' => 'https://cdn.example.test/image.jpg',
			'iikiti_content_optimization_twitter_card' => 'summary_large_image',
			'iikiti_content_optimization_twitter_title' => 'Card title',
			'iikiti_content_optimization_twitter_description' => 'Card description',
			'iikiti_content_optimization_twitter_image' => 'https://cdn.example.test/card.png',
		];

		self::assertSame($settings, $normalizer->normalize($settings));
	}

	#[DataProvider('invalidSettingsProvider')]
	public function testRejectsInvalidSettings(string $key, mixed $value, string $expectedMessage): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($expectedMessage);

		(new PageSeoSettingsNormalizer())->normalize([$key => $value]);
	}

	/**
	 * @return iterable<string, array{string, mixed, string}>
	 */
	public static function invalidSettingsProvider(): iterable
	{
		yield 'non-string value' => ['iikiti_content_optimization_title', ['not', 'text'], 'must be a string'];
		yield 'overlong title' => ['iikiti_content_optimization_title', str_repeat('a', 201), 'must not exceed 200 characters'];
		yield 'invalid robots value' => ['iikiti_content_optimization_robots_index', 'follow', 'must be one of: index, noindex'];
		yield 'invalid Open Graph type' => ['iikiti_content_optimization_og_type', 'profile', 'must be one of: website, article'];
		yield 'invalid Twitter card' => ['iikiti_content_optimization_twitter_card', 'player', 'must be one of: summary, summary_large_image'];
		yield 'insecure HTTP URL' => ['iikiti_content_optimization_twitter_image', 'http://cdn.example.test/card.png', 'must be an absolute HTTPS or root-relative URL'];
		yield 'protocol-relative URL' => ['iikiti_content_optimization_canonical_url', '//evil.example/path', 'must be an absolute HTTPS or root-relative URL'];
		yield 'script URL' => ['iikiti_content_optimization_og_image', 'javascript:alert(1)', 'must be an absolute HTTPS or root-relative URL'];
		yield 'data URL' => ['iikiti_content_optimization_twitter_image', 'data:image/png;base64,abc', 'must be an absolute HTTPS or root-relative URL'];
		yield 'other absolute scheme' => ['iikiti_content_optimization_canonical_url', 'ftp://example.test/file', 'must be an absolute HTTPS or root-relative URL'];
		yield 'non-root relative URL' => ['iikiti_content_optimization_canonical_url', 'articles/example', 'must be an absolute HTTPS or root-relative URL'];
		yield 'URL credentials' => ['iikiti_content_optimization_og_image', 'https://user:secret@example.test/image.jpg', 'must not contain credentials'];
		yield 'control character' => ['iikiti_content_optimization_title', "bad\nvalue", 'must not contain control characters'];
		yield 'Unicode control character' => ['iikiti_content_optimization_title', "bad\u{0085}value", 'must not contain control characters'];
		yield 'malformed URL' => ['iikiti_content_optimization_canonical_url', 'https://[::1', 'must be a well-formed URL'];
		yield 'invalid URL port' => ['iikiti_content_optimization_og_image', 'https://example.test:99999/image.jpg', 'must be a well-formed URL'];
		yield 'malformed percent escape' => ['iikiti_content_optimization_canonical_url', '/page%2', 'must be a well-formed URL'];
	}

	public function testWhitespaceOnlyValuesNormalizeToInheritedEmptyStrings(): void
	{
		$normalized = (new PageSeoSettingsNormalizer())->normalize([
			'iikiti_content_optimization_title' => '   ',
			'iikiti_content_optimization_robots_follow' => '',
		]);

		self::assertSame([
			'iikiti_content_optimization_title' => '',
			'iikiti_content_optimization_robots_follow' => '',
		], $normalized);
	}
}
