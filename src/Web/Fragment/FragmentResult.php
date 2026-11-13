<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

/** Trusted HTML and optional CSS produced by a registered fragment provider. */
final readonly class FragmentResult
{
	public const int MAX_HTML_BYTES = 1_048_576;
	public const int MAX_CSS_BYTES = 262_144;
	public const int MAX_STYLES = 32;

	/**
	 * @param list<array{id:?string,css:string}> $styles
	 */
	public function __construct(
		public string $html,
		public array $styles = [],
	) {
		if (strlen($html) > self::MAX_HTML_BYTES) {
			throw new \InvalidArgumentException('Fragment HTML exceeds the response size limit.');
		}
		if (count($styles) > self::MAX_STYLES) {
			throw new \InvalidArgumentException('Fragment styles exceed the response count limit.');
		}

		$totalCssBytes = 0;
		foreach ($styles as $style) {
			if (1 === preg_match('/<\/style/i', $style['css'])) {
				throw new \InvalidArgumentException('Fragment CSS cannot close its style element.');
			}
			$id = $style['id'];
			if (is_string($id) && ('' === $id || strlen($id) > 128 || 1 === preg_match('/[\x00-\x1F\x7F]/', $id))) {
				throw new \InvalidArgumentException('Invalid fragment style id.');
			}
			$totalCssBytes += strlen($style['css']);
			if ($totalCssBytes > self::MAX_CSS_BYTES) {
				throw new \InvalidArgumentException('Fragment CSS exceeds the response size limit.');
			}
		}
	}

	/** @return array{html:string,styles:list<array{id:?string,css:string}>} */
	public function toArray(): array
	{
		return ['html' => $this->html, 'styles' => $this->styles];
	}
}
