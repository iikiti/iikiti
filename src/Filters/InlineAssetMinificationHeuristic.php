<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

final class InlineAssetMinificationHeuristic
{
	public static function isAlreadyMinified(string $source): bool
	{
		return 1 !== preg_match('/\/\*|(^|[^:])\/\/|<!--|[\r\n]|\h{2,}/', $source);
	}

	/**
	 * CSS-specific already-minified check.
	 *
	 * Newlines are normal in formatted CSS and do not indicate minification, so
	 * they are intentionally excluded here. JavaScript keeps the shared check
	 * because newlines are a strong signal that JS source is unminified.
	 */
	public static function isAlreadyMinifiedCss(string $source): bool
	{
		return 1 !== preg_match('/\/\*|\h{2,}/', $source);
	}

	public static function containsPreservedCssComment(string $source): bool
	{
		$count = preg_match_all('~/\*([\s\S]*?)\*/~', $source, $matches);
		if (false === $count || 0 === $count) {
			return false;
		}

		foreach ($matches[1] as $comment) {
			if (str_starts_with($comment, '!') || false !== stripos($comment, '@license') || false !== stripos($comment, '@preserve')) {
				return true;
			}
		}

		return false;
	}
}
