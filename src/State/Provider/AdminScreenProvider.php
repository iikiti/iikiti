<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\ApiResource\AdminScreen;
use iikiti\CMS\ApiResource\AdminScreenResource;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Provides the admin screen manifest, aggregated from all registered
 * {@see \iikiti\CMS\Admin\AdminExtensionInterface} implementations.
 *
 * Screens whose `permission` is set are filtered so that the current user
 * only receives screens they are authorised to view. A permission string may
 * be either a Symfony role (e.g. `ROLE_ADMIN`) or a CMS permission written as
 * `{objectType}:{action}` (e.g. `BlogPost:read`).
 *
 * @implements ProviderInterface<AdminScreenResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class AdminScreenProvider implements ProviderInterface
{
	public function __construct(
		private AdminMenuRegistry $menuRegistry,
		private AuthorizationCheckerInterface $authorizationChecker,
		private PermissionChecker $permissionChecker,
		private Security $security,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$screens = $this->menuRegistry->getScreens();
		$user = $this->security->getUser();

		$filtered = array_filter($screens, function (AdminScreen $screen) use ($user): bool {
			if (null === $screen->permission) {
				return true;
			}

			if (str_starts_with($screen->permission, 'ROLE_')) {
				return $this->authorizationChecker->isGranted($screen->permission);
			}

			if ($user instanceof User && str_contains($screen->permission, ':')) {
				[$objectType, $action] = array_pad(explode(':', $screen->permission, 2), 2, '*');

				return $this->permissionChecker->canAccess($user, $objectType, $action);
			}

			return true;
		});

		return new AdminScreenResource(array_values($filtered));
	}
}
