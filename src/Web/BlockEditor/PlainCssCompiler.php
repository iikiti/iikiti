<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use InvalidArgumentException;

/**
 * Lightweight core fallback CSS compiler used when `plugins/iikiti-custom-css/`
 * is not active. Enforces basic length/selector/brace safety without SCSS.
 */
final class PlainCssCompiler implements CustomCssCompilerInterface
{
	private const MAX_SOURCE_LENGTH = 100_000;

	/** @var array<string,string> */
	private array $compiled = [];

	#[\Override]
	public function validate(string $source): string
	{
		return $this->compileScoped($source, '.iikiti-custom-css-scope-placeholder');
	}

	#[\Override]
	public function compileScoped(string $source, string $selector, ?int $minWidth = null): string
	{
		$source = trim($source);
		if ('' === $source) {
			return '';
		}
		if (strlen($source) > self::MAX_SOURCE_LENGTH) {
			throw new InvalidArgumentException('Custom CSS exceeds the 100 KB limit.');
		}
		if (null !== $minWidth && $minWidth < 0) {
			throw new InvalidArgumentException('CSS media breakpoint width cannot be negative.');
		}
		if (1 !== preg_match('/^[.#a-zA-Z0-9_\-\s>,\[\]="\\\\]+$/', $selector)) {
			throw new InvalidArgumentException('The CSS scope selector is invalid.');
		}
		if (str_contains(strtolower($source), '</style') || !$this->hasBalancedBraces($source)) {
			throw new InvalidArgumentException('Invalid CSS.');
		}

		$cacheKey = hash('sha256', $selector."\0".($minWidth ?? 'base')."\0".$source);
		if (isset($this->compiled[$cacheKey])) {
			return $this->compiled[$cacheKey];
		}

		$css = $selector.'{'.$source.'}';
		if (null !== $minWidth) {
			$css = '@media(min-width: '.$minWidth.'px){'.$css.'}';
		}
		if (count($this->compiled) >= 512) {
			$this->compiled = [];
		}
		$this->compiled[$cacheKey] = $css;

		return $css;
	}

	private function hasBalancedBraces(string $source): bool
	{
		$depth = 0;
		$length = strlen($source);
		$quote = null;
		for ($i = 0; $i < $length; ++$i) {
			$char = $source[$i];
			if (null !== $quote) {
				if ('\\' === $char) {
					++$i;
					continue;
				}
				if ($quote === $char) {
					$quote = null;
				}
				continue;
			}
			if ('/' === $char && $i + 1 < $length && '*' === $source[$i + 1]) {
				$end = strpos($source, '*/', $i + 2);
				if (false === $end) {
					return false;
				}
				$i = $end + 1;
				continue;
			}
			if ('"' === $char || "'" === $char) {
				$quote = $char;
				continue;
			}
			if ('{' === $char) {
				++$depth;
			} elseif ('}' === $char) {
				--$depth;
				if ($depth < 0) {
					return false;
				}
			}
		}

		return null === $quote && 0 === $depth;
	}
}
