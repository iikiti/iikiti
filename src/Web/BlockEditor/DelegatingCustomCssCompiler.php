<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Plugin\PluginActivationChecker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Selects the active plugin custom CSS compiler (respecting per-site and
 * application activation) and falls back to {@see PlainCssCompiler}.
 */
final class DelegatingCustomCssCompiler implements CustomCssCompilerInterface
{
	/**
	 * @param iterable<PluginCustomCssCompilerInterface> $compilers
	 */
	public function __construct(
		private readonly PlainCssCompiler $fallback,
		#[AutowireIterator('iikiti.cms.custom_css_compiler')]
		private readonly iterable $compilers = [],
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function validate(string $source): string
	{
		return $this->activeCompiler()->validate($source);
	}

	#[\Override]
	public function compileScoped(string $source, string $selector, ?int $minWidth = null): string
	{
		return $this->activeCompiler()->compileScoped($source, $selector, $minWidth);
	}

	private function activeCompiler(): CustomCssCompilerInterface
	{
		foreach ($this->compilers as $compiler) {
			if (null === $this->activationChecker || $this->activationChecker->isActive($compiler->getPluginSlug())) {
				return $compiler;
			}
		}

		return $this->fallback;
	}
}
