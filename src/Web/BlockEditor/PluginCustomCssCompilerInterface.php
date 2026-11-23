<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Plugin-provided custom CSS compiler (e.g. SCSS/advanced CSS), gated by
 * plugin activation state via {@see DelegatingCustomCssCompiler}.
 */
#[AutoconfigureTag('iikiti.cms.custom_css_compiler')]
interface PluginCustomCssCompilerInterface extends CustomCssCompilerInterface
{
	public function getPluginSlug(): string;
}
