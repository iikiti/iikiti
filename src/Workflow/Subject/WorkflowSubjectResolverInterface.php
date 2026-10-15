<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Subject;

use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workflow.subject_resolver')]
interface WorkflowSubjectResolverInterface
{
	public function getKey(): string;

	public function resolve(string $subjectId): ?object;

	public function isAllowedInSite(object $subject, Site $site): bool;
}
