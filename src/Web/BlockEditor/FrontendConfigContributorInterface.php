<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Allows plugins to contribute entries to the `window.iikiti.config` bootstrap
 * payload emitted by {@see FrontendConfigProvider}.
 */
#[AutoconfigureTag('iikiti.cms.frontend_config_contributor')]
interface FrontendConfigContributorInterface
{
	/**
	 * @param array<string,mixed> $settingsContext
	 *
	 * @return array<string,mixed>
	 */
	public function contributeConfig(int $contextId, string $contextType, User $user, array $settingsContext): array;
}
