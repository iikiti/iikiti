<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Explicitly registered providers are public/read-only rendering capabilities.
 * IDs use the owning plugin slug as a prefix, for example `my-plugin.banner`.
 */
#[AutoconfigureTag('iikiti.cms.fragment_provider')]
interface FragmentProviderInterface
{
	public function getName(): string;

	public function render(FragmentContext $context): FragmentResult;
}
