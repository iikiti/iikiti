<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Builds the front-end editor bootstrap config (`window.iakitti.config`) for the
 * page being rendered. Returns `null` when the current user is not authorised to
 * edit, so the live page stays clean (no editor markup/JS for visitors).
 */
final class FrontendConfigProvider
{
	/**
	 * @param array<string,mixed> $notificationDefaults
	 */
	public function __construct(
		private readonly Security $security,
		private readonly PermissionChecker $permissionChecker,
		private readonly ApiTokenManager $tokenManager,
		private readonly PluginRegistry $plugins,
		/** @var array<string,mixed> */
		private readonly array $notificationDefaults = ['autoDismiss' => true, 'durationSeconds' => 10, 'position' => 'bottom-right'],
	) {
	}

	/**
	 * @param 'template'|'object' $contextType
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(int $contextId, string $contextType, ?User $user = null): ?array
	{
		$user ??= $this->security->getUser();

		if (!$user instanceof User) {
			return null;
		}

		$canEdit = $this->permissionChecker->canAccess($user, 'Page', 'write') ||
			$this->permissionChecker->canAccess($user, 'Template', 'write');

		if (!$canEdit) {
			return null;
		}

		return [
			'canEdit' => true,
			'canPublish' => $this->permissionChecker->canAccess($user, 'Page', 'publish') ||
				$this->permissionChecker->canAccess($user, 'Template', 'publish'),
			'apiBase' => '/api',
			'apiToken' => $this->tokenManager->getOrCreateToken($user)->getToken(),
			'contextType' => $contextType,
			'contextId' => $contextId,
			'room' => $this->roomChannel($contextType, $contextId),
			'breakpoints' => ['sm' => 640, 'md' => 768, 'lg' => 1024, 'xl' => 1280],
			'notifications' => $this->notifications($user),
			'plugins' => $this->pluginEditorUis(),
		];
	}

	private function roomChannel(string $contextType, int $contextId): string
	{
		return sprintf('room/%s:%d', $contextType, $contextId);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function notifications(User $user): array
	{
		$settings = $this->notificationDefaults;
		$prefs = $user->getPreferences()->getJson();
		$editor = is_array($prefs['editor'] ?? null) ? $prefs['editor'] : [];
		$notif = is_array($editor['notifications'] ?? null) ? $editor['notifications'] : [];

		if (array_key_exists('autoDismiss', $notif)) {
			$settings['autoDismiss'] = (bool) $notif['autoDismiss'];
		}
		if (array_key_exists('durationSeconds', $notif)) {
			$settings['durationSeconds'] = (int) $notif['durationSeconds'];
		}
		if (array_key_exists('position', $notif) && is_string($notif['position'])) {
			$settings['position'] = $notif['position'];
		}

		return $settings;
	}

	/**
	 * @return list<array{slug:string, name:string, editor_ui:array<string,mixed>}>
	 */
	private function pluginEditorUis(): array
	{
		$uis = [];
		foreach ($this->plugins->getManifests() as $manifest) {
			$editorUi = $manifest->getEditorUi();
			if (null === $editorUi) {
				continue;
			}
			$uis[] = [
				'slug' => $manifest->slug,
				'name' => $manifest->name,
				'editor_ui' => $editorUi,
			];
		}

		return $uis;
	}
}
