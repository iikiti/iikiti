<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Service;

final class PageSeoSettingsNormalizer
{
	private const FIELD_RULES = [
		'iikiti_content_optimization_title' => ['maximumLength' => 200],
		'iikiti_content_optimization_description' => ['maximumLength' => 1000],
		'iikiti_content_optimization_canonical_url' => ['maximumLength' => 2048, 'url' => true],
		'iikiti_content_optimization_robots_index' => ['maximumLength' => 7, 'allowed' => ['index', 'noindex']],
		'iikiti_content_optimization_robots_follow' => ['maximumLength' => 8, 'allowed' => ['follow', 'nofollow']],
		'iikiti_content_optimization_og_title' => ['maximumLength' => 200],
		'iikiti_content_optimization_og_description' => ['maximumLength' => 1000],
		'iikiti_content_optimization_og_type' => ['maximumLength' => 7, 'allowed' => ['website', 'article']],
		'iikiti_content_optimization_og_image' => ['maximumLength' => 2048, 'url' => true],
		'iikiti_content_optimization_twitter_card' => ['maximumLength' => 20, 'allowed' => ['summary', 'summary_large_image']],
		'iikiti_content_optimization_twitter_title' => ['maximumLength' => 200],
		'iikiti_content_optimization_twitter_description' => ['maximumLength' => 1000],
		'iikiti_content_optimization_twitter_image' => ['maximumLength' => 2048, 'url' => true],
	];

	/**
	 * @param array<string, mixed> $settings
	 *
	 * @return array<string, string>
	 */
	public function normalize(array $settings): array
	{
		$normalized = [];

		foreach (self::FIELD_RULES as $key => $rule) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}

			$value = $settings[$key];
			if (!is_string($value)) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must be a string.', $key));
			}

			if (1 !== preg_match('//u', $value)) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must contain valid UTF-8 text.', $key));
			}

			if (1 === preg_match('/\p{Cc}/u', $value)) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must not contain control characters.', $key));
			}

			$value = trim($value);
			if ($this->length($value) > $rule['maximumLength']) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must not exceed %d characters.', $key, $rule['maximumLength']));
			}

			if ('' === $value) {
				$normalized[$key] = '';
				continue;
			}

			if (isset($rule['allowed']) && !in_array($value, $rule['allowed'], true)) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must be one of: %s.', $key, implode(', ', $rule['allowed'])));
			}

			if (($rule['url'] ?? false) === true) {
				$value = $this->normalizeUrl($key, $value);
			}

			$normalized[$key] = $value;
		}

		return $normalized;
	}

	private function normalizeUrl(string $key, string $value): string
	{
		if (1 === preg_match('/\s/u', $value) || str_contains($value, '\\') || 1 === preg_match('/%(?![A-F0-9]{2})/i', $value)) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must be a well-formed URL.', $key));
		}

		try {
			$parts = parse_url($value);
		} catch (\ValueError) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must be a well-formed URL.', $key));
		}
		if (false === $parts) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must be a well-formed URL.', $key));
		}

		if (str_starts_with($value, '/')) {
			if (str_starts_with($value, '//')) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must be an absolute HTTPS or root-relative URL.', $key));
			}

			if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" must be a well-formed URL.', $key));
			}

			return $value;
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		if ('https' !== $scheme) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must be an absolute HTTPS or root-relative URL.', $key));
		}

		if (false === filter_var($value, FILTER_VALIDATE_URL) || empty($parts['host'])) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must be a well-formed URL.', $key));
		}

		if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must not contain credentials.', $key));
		}

		return $value;
	}

	private function length(string $value): int
	{
		$count = preg_match_all('/./us', $value);

		return false === $count ? strlen($value) : $count;
	}
}
