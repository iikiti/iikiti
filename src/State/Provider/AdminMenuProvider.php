<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\ApiResource\AdminMenuResource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides the admin navigation menu, aggregated from all registered
 * {@see \iikiti\CMS\Admin\AdminExtensionInterface} implementations.
 *
 * @implements ProviderInterface<AdminMenuResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class AdminMenuProvider implements ProviderInterface
{
	public function __construct(
		private AdminMenuRegistry $menuRegistry,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		return new AdminMenuResource($this->menuRegistry->getMenu());
	}
}
