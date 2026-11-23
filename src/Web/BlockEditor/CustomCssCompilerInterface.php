<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

/**
 * Validates, scopes and compiles author-entered custom CSS.
 */
interface CustomCssCompilerInterface
{
	public function validate(string $source): string;

	public function compileScoped(string $source, string $selector, ?int $minWidth = null): string;
}
