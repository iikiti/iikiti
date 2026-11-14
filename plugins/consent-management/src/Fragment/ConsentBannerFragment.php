<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Fragment;

use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentResult;
use Twig\Environment;

/**
 * Renders the consent banner container, configuration block and the consent
 * manager script into public pages. Placed in the page <head> by the theme so
 * consent-based script blocking is active as early as possible.
 */
final class ConsentBannerFragment implements FragmentProviderInterface
{
	public function __construct(private readonly Environment $twig)
	{
	}

	public function getName(): string
	{
		return 'consent-management.banner';
	}

	public function render(FragmentContext $context): FragmentResult
	{
		$html = $this->twig->render('@ConsentManagement/fragments/banner.html.twig', [
			'site' => $context->site,
			'locale' => $context->locale,
		]);

		return new FragmentResult($html, [[
			'id' => 'consent-management.banner',
			'css' => '#iikiti-consent-banner{display:none}',
		]]);
	}
}
