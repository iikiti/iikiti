<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_definition_sites')]
class WorkflowDefinitionSiteAssignment
{
	#[ORM\Id]
	#[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'siteAssignments')]
	#[ORM\JoinColumn(name: 'definition_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', foreignKeyName: 'fk_workflow_definition_site_definition')]
	private WorkflowDefinition $definition;

	#[ORM\Id]
	#[ORM\ManyToOne(targetEntity: Site::class)]
	#[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', foreignKeyName: 'fk_workflow_definition_site_site')]
	private Site $site;

	public function __construct(WorkflowDefinition $definition, Site $site)
	{
		$this->definition = $definition;
		$this->site = $site;
	}

	public function getDefinition(): WorkflowDefinition
	{
		return $this->definition;
	}

	public function getSite(): Site
	{
		return $this->site;
	}
}
