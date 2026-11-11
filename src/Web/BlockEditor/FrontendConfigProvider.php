<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Event\Subscriber\RequestLocaleSubscriber;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\UserGroupRepository;
use iikiti\CMS\Response\Composition\JsonObjectComposer;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds the front-end editor bootstrap config (`window.iikiti.config`) for the
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
		#[Autowire('%env(string:INTERNAL_API_BASE_URL)%')]
		private readonly string $apiBaseUrl = '',
		private readonly ?RequestStack $requestStack = null,
		private readonly ?UserGroupRepository $userGroups = null,
	) {
	}

	/**
	 * @param 'template'|'object' $contextType
	 * @param int                 $version     Current draft version the editor must echo back as
	 *                                         `If-Match` on save (omit to keep the legacy 0 default)
	 * @param array<string,mixed> $settingsContext
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(
		int $contextId,
		string $contextType,
		?User $user = null,
		int $version = 0,
		array $settingsContext = [],
	): ?array
	{
		$user ??= $this->security->getUser();

		if (!$user instanceof User) {
			return null;
		}

		if (!$this->canEdit($user)) {
			return null;
		}

		$composer = new JsonObjectComposer();
		$composer->add([
			'canEdit' => true,
			'canPublish' => $this->permissionChecker->canAccess($user, 'Page', 'publish') ||
				$this->permissionChecker->canAccess($user, 'Template', 'publish'),
			'apiBase' => '' === trim($this->apiBaseUrl) ? '/api' : rtrim($this->apiBaseUrl, '/').'/api',
			'apiSiteHost' => $this->apiSiteHost(),
			'apiSiteScheme' => $this->apiSiteScheme(),
			'apiToken' => $this->tokenManager->getOrCreateToken($user)->getToken(),
		], order: 10);
		$composer->add([
			'contextType' => $contextType,
			'contextId' => $contextId,
			'version' => $version,
			'room' => $this->roomChannel($contextType, $contextId),
		], order: 20);
		$composer->add([
			'breakpoints' => ResponsiveBreakpoints::WIDTHS,
			'notifications' => $this->notifications($user),
			'timezone' => $user->getTimeZone()?->getId(),
			'locale' => $this->presentationLocale($user),
			'cacheGroups' => $this->cacheGroups(),
		], order: 30);
		$composer->add([
			'plugins' => $this->pluginEditorUis(),
		], order: 40);
		$composer->add([
			'settingsContextType' => $settingsContext['type'] ?? $contextType,
			'settingsContextId' => $settingsContext['id'] ?? $contextId,
			'settingsLabel' => $settingsContext['label'] ?? 'Template Settings',
			'settingsValues' => $settingsContext['values'] ?? [],
			'settingsVersion' => $settingsContext['version'] ?? 0,
			'settingsHasDraft' => $settingsContext['hasDraft'] ?? false,
		], order: 50);

		return $composer->compose();
	}

	/**
	 * Whether the given (or current) user may author pages/templates. Used both to
	 * decide whether to emit the editor bootstrap config and — separately — to gate
	 * editor-only placeholder content (e.g. the "Edit this page with ?edit" hint)
	 * regardless of whether `?edit` is in the URL.
	 */
	public function canEdit(?UserInterface $user = null): bool
	{
		$user ??= $this->security->getUser();

		if (!$user instanceof User) {
			return false;
		}

		return $this->permissionChecker->canAccess($user, 'Page', 'write') ||
			$this->permissionChecker->canAccess($user, 'Template', 'write');
	}

	public function canAccess(?UserInterface $user, string $objectType, string $action, ?DbObject $object = null): bool
	{
		return $user instanceof User && $this->permissionChecker->canAccess($user, $objectType, $action, $object);
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

	private function apiSiteHost(): string
	{
		$request = $this->requestStack?->getCurrentRequest();

		return strtolower((string) ($request?->headers->get('X-SITE-HOST') ?: $request?->getHost() ?: ''));
	}

	private function apiSiteScheme(): string
	{
		$request = $this->requestStack?->getCurrentRequest();
		$scheme = $request?->headers->get('X-SITE-SCHEME') ?: $request?->getScheme() ?: 'https';

		return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
	}

	private function presentationLocale(User $user): string
	{
		$request = $this->requestStack?->getCurrentRequest();
		$context = $request?->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE);
		$locale = is_array($context) ? ($context['presentation'] ?? null) : null;
		if (is_string($locale) && '' !== $locale) {
			return $locale;
		}

		return $user->getPreferredLocale()?->getTag() ?? str_replace('_', '-', $request?->getLocale() ?? 'en');
	}

	/** @return list<array{id:string,label:string}> */
	private function cacheGroups(): array
	{
		if (null === $this->userGroups || !SiteRegistry::hasCurrent()) {
			return [];
		}

		$siteId = SiteRegistry::getCurrent()->getId();
		if (null === $siteId) {
			return [];
		}

		$groups = [];
		foreach ($this->userGroups->findAvailableForSite((int) $siteId) as $group) {
			$id = $group->getId();
			if (null === $id) {
				continue;
			}
			$groups[] = [
				'id' => (string) $id,
				'label' => $group->getLabel() ?: $group->getName() ?: sprintf('Group %s', $id),
			];
		}

		return $groups;
	}
}
