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
		'iikiti_content_optimization_og_image' => ['maximumLength' => 2048, 'url' => true, 'media' => true],
		'iikiti_content_optimization_twitter_card' => ['maximumLength' => 20, 'allowed' => ['summary', 'summary_large_image']],
		'iikiti_content_optimization_twitter_title' => ['maximumLength' => 200],
		'iikiti_content_optimization_twitter_description' => ['maximumLength' => 1000],
		'iikiti_content_optimization_twitter_image' => ['maximumLength' => 2048, 'url' => true, 'media' => true],
	];

	private const ALLOWED_MEDIA_KEYS = [
		'source',
		'assetId',
		'publicToken',
		'url',
		'previewUrl',
		'thumbnailUrl',
		'mimeType',
		'width',
		'height',
		'focalPoint',
		'dominantColor',
		'defaultAlt',
	];

	/**
	 * @param array<string, mixed> $settings
	 *
	 * @return array<string, string|array<string, mixed>>
	 */
	public function normalize(array $settings): array
	{
		$normalized = [];

		foreach (self::FIELD_RULES as $key => $rule) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}

			$value = $settings[$key];
			if (($rule['media'] ?? false) === true && is_array($value)) {
				$normalized[$key] = $this->normalizeMediaObject($key, $value, $rule['maximumLength']);
				continue;
			}

			if (!is_string($value)) {
				$message = ($rule['media'] ?? false) === true
					? sprintf('Setting "%s" must be a string or media object.', $key)
					: sprintf('Setting "%s" must be a string.', $key);
				throw new \InvalidArgumentException($message);
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

	/**
	 * @param array<mixed> $value
	 *
	 * @return string|array<string, mixed>
	 */
	private function normalizeMediaObject(string $key, array $value, int $maximumLength): string|array
	{
		foreach (array_keys($value) as $field) {
			if (!is_string($field) || !in_array($field, self::ALLOWED_MEDIA_KEYS, true)) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" contains an unsupported media field.', $key));
			}
		}

		$source = $value['source'] ?? null;
		if (!is_string($source)) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" media source must be "asset" or "url".', $key));
		}
		$source = strtolower(trim($source));

		if ('empty' === $source) {
			return '';
		}

		if ('asset' === $source) {
			$rawAssetId = $value['assetId'] ?? null;
			if (null === $rawAssetId || '' === $rawAssetId) {
				return '';
			}
			$assetId = $this->normalizePositiveInt($rawAssetId);
			if (null === $assetId) {
				throw new \InvalidArgumentException(sprintf('Setting "%s" assetId must be a positive integer.', $key));
			}

			$result = [
				'source' => 'asset',
				'assetId' => $assetId,
			];

			if (array_key_exists('publicToken', $value) && null !== $value['publicToken'] && '' !== $value['publicToken']) {
				if (!is_string($value['publicToken']) || 1 !== preg_match('/^[a-f0-9]{64}$/i', trim($value['publicToken']))) {
					throw new \InvalidArgumentException(sprintf('Setting "%s" publicToken is invalid.', $key));
				}
				$result['publicToken'] = strtolower(trim($value['publicToken']));
			}

			foreach (['url', 'previewUrl', 'thumbnailUrl'] as $urlKey) {
				if (array_key_exists($urlKey, $value) && null !== $value[$urlKey] && '' !== $value[$urlKey]) {
					$url = $this->normalizeStringText($key, $value[$urlKey], $maximumLength);
					if ('' !== $url) {
						$result[$urlKey] = $this->normalizeUrl($key, $url);
					}
				}
			}

			if (array_key_exists('focalPoint', $value) && null !== $value['focalPoint']) {
				$result['focalPoint'] = $this->normalizeFocalPoint($key, $value['focalPoint']);
			}

			return $result;
		}

		if ('url' === $source) {
			$rawUrl = $value['url'] ?? '';
			if (null === $rawUrl) {
				return '';
			}
			$url = $this->normalizeStringText($key, $rawUrl, $maximumLength);
			if ('' === $url) {
				return '';
			}

			return [
				'source' => 'url',
				'url' => $this->normalizeUrl($key, $url),
			];
		}

		throw new \InvalidArgumentException(sprintf('Setting "%s" media source must be "asset" or "url".', $key));
	}

	private function normalizeStringText(string $key, mixed $value, int $maximumLength): string
	{
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
		if ($this->length($value) > $maximumLength) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" must not exceed %d characters.', $key, $maximumLength));
		}

		return $value;
	}

	private function normalizePositiveInt(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' !== $trimmed && ctype_digit($trimmed)) {
				$parsed = (int) $trimmed;

				return $parsed > 0 ? $parsed : null;
			}
		}

		return null;
	}

	/** @return array{x: float, y: float} */
	private function normalizeFocalPoint(string $key, mixed $value): array
	{
		if (
			!is_array($value)
			|| !array_key_exists('x', $value)
			|| !array_key_exists('y', $value)
			|| (!is_int($value['x']) && !is_float($value['x']))
			|| (!is_int($value['y']) && !is_float($value['y']))
		) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" focalPoint must provide numeric x and y coordinates between 0.0 and 1.0.', $key));
		}

		$x = (float) $value['x'];
		$y = (float) $value['y'];
		if (!is_finite($x) || !is_finite($y) || $x < 0.0 || $x > 1.0 || $y < 0.0 || $y > 1.0) {
			throw new \InvalidArgumentException(sprintf('Setting "%s" focalPoint must provide numeric x and y coordinates between 0.0 and 1.0.', $key));
		}

		return ['x' => $x, 'y' => $y];
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
