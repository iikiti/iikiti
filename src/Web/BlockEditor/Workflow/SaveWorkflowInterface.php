<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Workflow;

use iikiti\CMS\Entity\Object\User;

/**
 * Pluggable save workflow for editor state (draft/publish lifecycle).
 *
 * Core ships {@see DraftPublishWorkflow} (draft + autosave + publish); admins
 * and plugins can register additional workflows by implementing this interface
 * and the `iikiti.cms.save_workflow` tag.
 */
interface SaveWorkflowInterface
{
	public function getName(): string;

	/**
	 * Persist a (draft) block tree snapshot for the given context.
	 *
	 * @param array<string,list<array<string,mixed>>> $tree
	 * @param string|null                             $ifMatchVersion ETag-style version; mismatch => conflict
	 *
	 * @return array{ok:bool, version:int, conflict:bool}
	 */
	public function save(string $contextType, int $contextId, array $tree, ?string $ifMatchVersion, User $user): array;

	/**
	 * Promote draft state to published for the given context.
	 *
	 * @return array{ok:bool}
	 */
	public function publish(string $contextType, int $contextId, User $user): array;
}
