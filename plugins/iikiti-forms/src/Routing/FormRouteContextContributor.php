<?php

declare(strict_types=1);

namespace iikiti\Extension\Forms\Routing;

use iikiti\CMS\Registry\AbstractRouteContextContributor;

/**
 * Marks multi-step form endpoints as session-backed routes for CSRF and
 * Consumer-to-API origin rewriting.
 */
final class FormRouteContextContributor extends AbstractRouteContextContributor
{
	#[\Override]
	public function getSessionFormPathPrefixes(): array
	{
		return ['/api/forms/'];
	}
}
