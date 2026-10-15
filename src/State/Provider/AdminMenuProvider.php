<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminMenuResource;
use Symfony\Bundle\SecurityBundle\Security;
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
		private Security $security,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$items = [];
		foreach ($this->menuRegistry->getMenu() as $item) {
			$visible = $this->filterItem($item);
			if (null !== $visible) {
				$items[] = $visible;
			}
		}

		return new AdminMenuResource($items);
	}

	private function filterItem(AdminMenuItem $item): ?AdminMenuItem
	{
		$children = [];
		foreach ($item->children as $child) {
			$visibleChild = $this->filterItem($child);
			if (null !== $visibleChild) {
				$children[] = $visibleChild;
			}
		}
		$permission = $item->permission ?? 'ROLE_ADMIN';
		if (!$this->security->isGranted($permission) && [] === $children) {
			return null;
		}

		return new AdminMenuItem($item->label, $item->path, $item->icon, $item->badge, $children, $item->priority, $item->permission);
	}
}
