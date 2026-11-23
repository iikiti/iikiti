<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Fragment;

use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\Extension\ConsentManagement\Service\ConsentService;
use Twig\Environment;

/**
 * Renders the consent banner container, configuration block and the consent
 * manager scripts into the page body when Consent Management is active.
 */
final class ConsentBannerFragment implements FragmentProviderInterface
{
	public function __construct(
		private readonly Environment $twig,
		private readonly ?ConsentService $consentService = null,
	) {
	}

	public function getName(): string
	{
		return 'consent-management.banner';
	}

	public function render(FragmentContext $context): FragmentResult
	{
		$config = $this->consentService?->publicConfig($context->site) ?? [
			'version' => 1,
			'default_categories' => ['necessary' => true, 'analytics' => false, 'marketing' => false],
		];
		$configJson = json_encode(
			$config,
			JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
		);
		$html = $this->twig->render('@ConsentManagement/fragments/banner.html.twig', [
			'site' => $context->site,
			'locale' => $context->locale,
			'consentConfigJson' => $configJson,
		]);

		return new FragmentResult($html);
	}
}
