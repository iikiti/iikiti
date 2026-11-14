<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

final readonly class InlineAssetMinificationOptions
{
	public const REQUEST_ATTRIBUTE = '_iikiti_inline_asset_minification';

	public function __construct(
		public bool $cssMinify,
		public bool $jsMinify,
		public bool $jsUglify,
	) {
	}

	/** @return array{css_minify:bool,js_minify:bool,js_uglify:bool} */
	public function toArray(): array
	{
		return [
			'css_minify' => $this->cssMinify,
			'js_minify' => $this->jsMinify,
			'js_uglify' => $this->jsUglify,
		];
	}

	public static function fromArray(mixed $options): ?self
	{
		if (!is_array($options) ||
			!is_bool($options['css_minify'] ?? null) ||
			!is_bool($options['js_minify'] ?? null) ||
			!is_bool($options['js_uglify'] ?? null)) {
			return null;
		}

		return new self($options['css_minify'], $options['js_minify'], $options['js_uglify']);
	}
}
