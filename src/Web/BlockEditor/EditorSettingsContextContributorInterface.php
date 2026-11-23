<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Allows plugins to customize editor settings context (target selection,
 * context options, and metadata defaults) without hardcoding plugin logic in
 * {@see PageRendering}.
 */
#[AutoconfigureTag('iikiti.cms.editor_settings_contributor')]
interface EditorSettingsContextContributorInterface
{
	/**
	 * @param array<string,mixed> $context Current mutable settings context
	 * @param array<string,mixed> $state   Additional page/request state
	 *
	 * @return array<string,mixed> Updated settings context
	 */
	public function contributeSettingsContext(
		array $context,
		?DbObject $object,
		Template $template,
		?User $user,
		bool $home,
		?Site $site,
		Request $request,
		array $state = [],
	): array;
}
