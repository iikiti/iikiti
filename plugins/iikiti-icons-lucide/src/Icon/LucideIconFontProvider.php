<?php

declare(strict_types=1);

namespace iikiti\Extension\IconsLucide\Icon;

use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Web\Icon\IconFontStylesheetProviderInterface;

final class LucideIconFontProvider implements IconFontStylesheetProviderInterface
{
	public const STYLESHEET_PATH = '/plugin-assets/iikiti-icons-lucide/font/lucide.css';

	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function getFontStylesheetPath(): ?string
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-icons-lucide')) {
			return null;
		}

		return self::STYLESHEET_PATH;
	}
}
