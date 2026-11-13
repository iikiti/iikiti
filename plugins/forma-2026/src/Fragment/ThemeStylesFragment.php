<?php

declare(strict_types=1);

namespace iikiti\Extension\Forma2026\Fragment;

use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentResult;

/** Supplies this plugin's static theme stylesheet through the shared Fragment API. */
final class ThemeStylesFragment implements FragmentProviderInterface
{
	private readonly string $stylesheet;

	public function __construct()
	{
		$stylesheet = file_get_contents(dirname(__DIR__, 2).'/assets/theme.css');
		if (false === $stylesheet) {
			throw new \RuntimeException('Forma-2026 stylesheet could not be loaded.');
		}

		$this->stylesheet = $stylesheet;
	}

	public function getName(): string
	{
		return 'forma-2026.theme-styles';
	}

	public function render(FragmentContext $context): FragmentResult
	{
		return new FragmentResult('', [[
			'id' => 'forma-2026.theme-styles',
			'css' => $this->stylesheet,
		]]);
	}
}
