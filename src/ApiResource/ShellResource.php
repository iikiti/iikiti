<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\State\Processor\ShellProcessor;
use iikiti\CMS\State\Provider\ShellStateProvider;

/**
 * Global layout shells (header, footer, sidebar, dialog). Admin-only.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/shells',
			name: 'admin_shell_list',
			provider: ShellStateProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/shells/{id}',
			name: 'admin_shell_get',
			provider: ShellStateProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/shells',
			name: 'admin_shell_create',
			processor: ShellProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/shells/{id}',
			name: 'admin_shell_update',
			processor: ShellProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
final class ShellResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public ?string $name = null,
		public ?string $role = null,
		public ?int $priority = null,
		public ?bool $enabled = null,
		/** @var list<array<string,mixed>>|null */
		public ?array $displayRules = null,
		/** @var list<array<string,mixed>>|null */
		public ?array $blocks = null,
	) {
	}

	public static function fromEntity(Shell $shell): self
	{
		return new self(
			id: $shell->getId() === null ? null : (int) $shell->getId(),
			name: $shell->getName(),
			role: $shell->getRole(),
			priority: $shell->getPriority(),
			enabled: $shell->isEnabled(),
			displayRules: $shell->getDisplayRules(),
			blocks: $shell->getBlocks(),
		);
	}
}
