<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Allows plugins to own custom editor settings targets (beyond core `page` /
 * `template`) and normalize/validate settings payloads on save & publish.
 */
#[AutoconfigureTag('iikiti.cms.editor_settings_handler')]
interface EditorSettingsHandlerInterface
{
	public function supportsTarget(string $targetType): bool;

	/**
	 * Load and authorize a plugin-owned settings target.
	 *
	 * @return array{0:DbObject|null, 1:JsonResponse|null}
	 */
	public function authorizeAndLoadTarget(string $targetType, int|string $targetId, User $user, string $action): array;

	public function auditObjectType(string $targetType): ?string;

	/**
	 * Normalize/validate settings values before saving a draft for any target type.
	 *
	 * @param array<string,mixed> $values
	 * @param array<string,mixed> $currentState
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \InvalidArgumentException when settings values are invalid
	 */
	public function prepareSaveValues(string $targetType, DbObject $target, array $values, array $currentState): array;

	/**
	 * Validate/normalize draft settings before publishing. Return a blocking
	 * error response, or `null` when publishing may proceed.
	 *
	 * @param array<string,mixed> $state
	 */
	public function validateAndPreparePublish(
		string $targetType,
		DbObject $target,
		array $state,
		?string $ifMatch,
	): ?JsonResponse;
}
