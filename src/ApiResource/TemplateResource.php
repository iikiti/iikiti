<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\State\Processor\TemplateProcessor;
use iikiti\CMS\State\Provider\TemplateStateProvider;

/**
 * @experimental Front-end editor template management is API-first. Write
 *             operations are gated behind Page:write / Template permissions via
 *             {@see \iikiti\CMS\State\Provider\EditorContextProvider} and the
 *             template save workflow.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/templates',
			name: 'admin_template_list',
			provider: TemplateStateProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
			normalizationContext: ['groups' => ['template:read']],
		),
		new Get(
			uriTemplate: '/admin/templates/{id}',
			name: 'admin_template_get',
			provider: TemplateStateProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
			normalizationContext: ['groups' => ['template:read']],
		),
		new Post(
			uriTemplate: '/admin/templates',
			name: 'admin_template_create',
			processor: TemplateProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
			normalizationContext: ['groups' => ['template:read']],
		),
		new Put(
			uriTemplate: '/admin/templates/{id}',
			name: 'admin_template_update',
			processor: TemplateProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
			normalizationContext: ['groups' => ['template:read']],
		),
	],
)]
final class TemplateResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public ?string $title = null,
		public ?string $layout = null,
		/** @var list<array<string,mixed>>|null */
		public ?array $regions = null,
		/** @var array<string,list<array<string,mixed>>>|null */
		public ?array $blocks = null,
		/** @var list<array<string,mixed>>|null */
		public ?array $assignments = null,
		/** @var array<string,mixed>|null */
		public ?array $settings = null,
	) {
	}

	public static function fromEntity(Template $template): self
	{
		return new self(
			id: $template->getId(),
			title: $template->getTitle(),
			layout: $template->getLayout(),
			regions: $template->getRegions() ?: null,
			blocks: $template->getBlocks(),
			assignments: $template->getAssignments() ?: null,
			settings: $template->getSettings() ?: null,
		);
	}
}
