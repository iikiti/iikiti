<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

/**
 * Safe, structured page-rendering contributions returned by active plugins.
 */
final class PageRenderContribution
{
	/**
	 * @param string|null                         $title        Optional override for document `<title>`
	 * @param list<string>                        $headHtml     Pre-escaped HTML snippets appended inside `<head>`
	 * @param list<string>                        $bodyClasses  CSS classes appended to `<body>`
	 * @param list<string>                        $bodyBefore   HTML fragments inserted at the start of `<body>`
	 * @param list<string>                        $bodyAfter    HTML fragments inserted at the end of `<body>`
	 * @param array<string,mixed>                 $jsonBlocks   Named JSON payloads emitted as `<script id="..." type="application/json">`
	 * @param array<string,mixed>                 $templateVars Extra variables merged into `base/layout.twig`
	 */
	public function __construct(
		public readonly ?string $title = null,
		public readonly array $headHtml = [],
		public readonly array $bodyClasses = [],
		public readonly array $bodyBefore = [],
		public readonly array $bodyAfter = [],
		public readonly array $jsonBlocks = [],
		public readonly array $templateVars = [],
	) {
	}
}
