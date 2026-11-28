<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

/**
 * Extracts structured DAM asset references and `/assets/{64-hex}/` URLs from
 * page/template `settings`, `settings_draft`, and Site/Application `configuration`
 * payloads (such as `og_image`, `twitter_image`, `defaultSocialImage`, logos, etc.).
 */
final class SettingsAssetUsageExtractor implements AssetUsageExtractorInterface
{
	/**
	 * @return list<array{assetId?: int, publicToken?: string, fieldPath: string}>
	 */
	#[\Override]
	public function extractFromValue(mixed $value, string $basePath): array
	{
		$lowerBase = strtolower(trim($basePath));
		if (
			str_starts_with($lowerBase, 'blocks')
			|| str_starts_with($lowerBase, 'dynamic_blocks')
		) {
			return [];
		}

		$collected = [];
		$seen = [];
		$this->walk($value, $basePath, $collected, $seen);

		return $collected;
	}

	/**
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}> $collected
	 * @param array<string, true>                                                 $seen
	 */
	private function walk(mixed $value, string $path, array &$collected, array &$seen): void
	{
		if (is_string($value)) {
			$this->extractTokensFromString($value, $path, $collected, $seen);

			return;
		}

		if (!is_array($value)) {
			return;
		}

		$source = isset($value['source']) && is_string($value['source']) ? strtolower(trim($value['source'])) : null;
		if ('asset' === $source || (!array_key_exists('source', $value) && array_key_exists('assetId', $value))) {
			$assetId = $this->normalizePositiveInt($value['assetId'] ?? null);
			if (null !== $assetId) {
				$this->recordAssetId($assetId, $path, $collected, $seen);

				return;
			}
			$publicToken = $this->normalizePublicToken($value['publicToken'] ?? null);
			if (null !== $publicToken) {
				$this->recordPublicToken($publicToken, $path, $collected, $seen);

				return;
			}
		}

		if (array_is_list($value)) {
			foreach ($value as $index => $item) {
				$this->walk($item, sprintf('%s[%d]', $path, $index), $collected, $seen);
			}

			return;
		}

		foreach ($value as $key => $nested) {
			$nextPath = '' !== $path ? $path.'.'.$key : (string) $key;
			$this->walk($nested, $nextPath, $collected, $seen);
		}
	}

	/**
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}> $collected
	 * @param array<string, true>                                                 $seen
	 */
	private function extractTokensFromString(string $text, string $path, array &$collected, array &$seen): void
	{
		if (!str_contains($text, '/assets/')) {
			return;
		}
		if (1 > preg_match_all(BlockTreeAssetUsageExtractor::ASSET_URL_PATTERN, $text, $matches)) {
			return;
		}
		foreach ($matches[1] as $token) {
			$this->recordPublicToken(strtolower($token), $path, $collected, $seen);
		}
	}

	/**
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}> $collected
	 * @param array<string, true>                                                 $seen
	 */
	private function recordAssetId(int $assetId, string $fieldPath, array &$collected, array &$seen): void
	{
		$normalizedPath = mb_substr($fieldPath, 0, 255);
		$key = 'id:'.$assetId.'|'.$normalizedPath;
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		$collected[] = [
			'assetId' => $assetId,
			'fieldPath' => $normalizedPath,
		];
	}

	/**
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}> $collected
	 * @param array<string, true>                                                 $seen
	 */
	private function recordPublicToken(string $publicToken, string $fieldPath, array &$collected, array &$seen): void
	{
		$normalizedPath = mb_substr($fieldPath, 0, 255);
		$key = 'tok:'.$publicToken.'|'.$normalizedPath;
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		$collected[] = [
			'publicToken' => $publicToken,
			'fieldPath' => $normalizedPath,
		];
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

	private function normalizePublicToken(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$trimmed = strtolower(trim($value));

		return 1 === preg_match('/^[a-f0-9]{64}$/', $trimmed) ? $trimmed : null;
	}
}
