<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use InvalidArgumentException;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use ScssPhp\ScssPhp\Syntax;
use Throwable;

/** Validates, scopes and minifies author-entered CSS. */
final class CustomCssCompiler
{
	private const MAX_SOURCE_LENGTH = 100_000;

	/** @var array<string,string> */
	private array $compiled = [];

	public function validate(string $source): string
	{
		return $this->compileScoped($source, '.iikiti-custom-css-scope-placeholder');
	}

	public function compileScoped(string $source, string $selector, ?int $minWidth = null): string
	{
		$source = trim($source);
		if ('' === $source) {
			return '';
		}
		if (strlen($source) > self::MAX_SOURCE_LENGTH) {
			throw new InvalidArgumentException('Custom CSS exceeds the 100 KB limit.');
		}
		if (1 !== preg_match('/^[.#a-zA-Z0-9_\-\s>,\[\]="\\\\]+$/', $selector)) {
			throw new InvalidArgumentException('The CSS scope selector is invalid.');
		}
		$cacheKey = hash('sha256', $selector."\0".($minWidth ?? 'base')."\0".$source);
		if (isset($this->compiled[$cacheKey])) {
			return $this->compiled[$cacheKey];
		}

		$scopedRules = $selector.'{'.$source.'}';
		if (null !== $minWidth) {
			$scopedRules = '@media (min-width:'.$minWidth.'px){'.$scopedRules.'}';
		}
		$compiler = new Compiler();
		$compiler->setOutputStyle(OutputStyle::COMPRESSED);

		try {
			$css = trim($compiler->compileString($scopedRules, syntax: Syntax::CSS)->getCss());
			if (count($this->compiled) >= 512) {
				$this->compiled = [];
			}
			$this->compiled[$cacheKey] = $css;

			return $css;
		} catch (Throwable $error) {
			throw new InvalidArgumentException($this->messageFor($error), previous: $error);
		}
	}

	private function messageFor(Throwable $error): string
	{
		$message = trim(preg_replace('/\s+/', ' ', $error->getMessage()) ?? 'Invalid CSS.');

		return '' === $message ? 'Invalid CSS.' : substr($message, 0, 240);
	}
}
