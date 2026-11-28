<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

/**
 * Recursively extracts DAM asset references (`{ source: 'asset', assetId }` and
 * `/assets/{64-hex}/` URLs inside strings/richtext) from block trees and region maps.
 */
final class BlockTreeAssetUsageExtractor implements AssetUsageExtractorInterface
{
	public const ASSET_URL_PATTERN = '~(?:/assets/(?:signed/)?)([a-f0-9]{64})(?=/|$|[?#"\'\s)&>])~i';

	/**
	 * @return list<array{assetId?: int, publicToken?: string, fieldPath: string}>
	 */
	#[\Override]
	public function extractFromValue(mixed $value, string $basePath): array
	{
		if (!is_array($value) || [] === $value) {
			return [];
		}

		$lowerBase = strtolower(trim($basePath));
		if (
			str_starts_with($lowerBase, 'settings')
			|| str_starts_with($lowerBase, 'configuration')
		) {
			return [];
		}

		$collected = [];
		$seen = [];

		if (array_is_list($value)) {
			if ($this->looksLikeNodeList($value)) {
				$this->walkNodeList($value, $basePath, $collected, $seen);
			}
		} else {
			foreach ($value as $region => $nodes) {
				if (!is_array($nodes) || [] === $nodes) {
					continue;
				}
				if (array_is_list($nodes) && $this->looksLikeNodeList($nodes)) {
					$regionPath = '' !== $basePath ? $basePath.'.'.$region : (string) $region;
					$this->walkNodeList($nodes, $regionPath, $collected, $seen);
				}
			}
		}

		return $collected;
	}

	/**
	 * @param list<mixed>                                                          $nodes
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}>  $collected
	 * @param array<string, true>                                                  $seen
	 */
	private function walkNodeList(array $nodes, string $prefix, array &$collected, array &$seen): void
	{
		foreach ($nodes as $index => $node) {
			if (!is_array($node)) {
				continue;
			}
			$nodePath = sprintf('%s[%d]', $prefix, $index);

			if (isset($node['content']) && is_array($node['content'])) {
				$this->walkContent($node['content'], $nodePath, $collected, $seen);
			}

			if (isset($node['children']) && is_array($node['children']) && array_is_list($node['children'])) {
				$this->walkNodeList($node['children'], $nodePath.'.children', $collected, $seen);
			}
		}
	}

	/**
	 * @param list<array{assetId?: int, publicToken?: string, fieldPath: string}> $collected
	 * @param array<string, true>                                                 $seen
	 */
	private function walkContent(mixed $value, string $path, array &$collected, array &$seen): void
	{
		if (is_string($value)) {
			$this->extractTokensFromString($value, $path, $collected, $seen);

			return;
		}

		if (!is_array($value)) {
			return;
		}

		$source = isset($value['source']) && is_string($value['source']) ? strtolower(trim($value['source'])) : null;
		if ('asset' === $source) {
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
			foreach ($value as $idx => $item) {
				$this->walkContent($item, sprintf('%s[%d]', $path, $idx), $collected, $seen);
			}

			return;
		}

		foreach ($value as $key => $nested) {
			$nextPath = '' !== $path ? $path.'.'.$key : (string) $key;
			$this->walkContent($nested, $nextPath, $collected, $seen);
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
		if (1 > preg_match_all(self::ASSET_URL_PATTERN, $text, $matches)) {
			return;
		}
		foreach ($matches[1] as $token) {
			$this->recordPublicToken(strtolower($token), $path, $collected, $seen);
		}
	}

	/**
	 * @param list<mixed> $list
	 */
	private function looksLikeNodeList(array $list): bool
	{
		foreach ($list as $item) {
			if (is_array($item) && (isset($item['type']) || isset($item['content']) || isset($item['children']))) {
				return true;
			}
		}

		return false;
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
