<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;

/**
 * Shared authorization predicate for editing/publishing site-scoped Homepage SEO
 * settings, ensuring identical checks across the editor UI and settings API.
 */
final readonly class HomepageSeoAccessPolicy
{
	public const CONTENT_OPTIMIZATION_PLUGIN_SLUG = 'iikiti-content-optimization';

	public function __construct(
		private PermissionChecker $permissions,
		private WorkflowAccessPolicy $workflowAccess,
	) {
	}

	public function canEdit(User $user, Site $site, ?Template $homepageTemplate = null): bool
	{
		if (!in_array(self::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true)) {
			return false;
		}

		if (!$this->workflowAccess->canManageSite($user, $site)) {
			return false;
		}

		// Normalize an unpersisted fallback Template (id === null) to null so
		// object-level ACL checks behave identically in PageRendering and API calls.
		$persistedTemplate = null !== $homepageTemplate && null !== $homepageTemplate->getId()
			? $homepageTemplate
			: null;

		return $this->permissions->canAccess($user, 'Template', 'publish', $persistedTemplate);
	}
}
