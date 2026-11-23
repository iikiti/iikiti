<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Editor;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\BlockEditorComponent;
use iikiti\CMS\Web\BlockEditor\CustomCssCompilerInterface;
use iikiti\CMS\Web\BlockEditor\EditorSettingsHandlerInterface;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Workflow\SaveWorkflowRegistry;
use iikiti\CMS\Web\Icon\IconResolver;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Front-end block-editor API. All endpoints require a valid API token (the
 * ephemeral token issued to authorised editors) and the relevant write
 * permission, enforced here with {@see PermissionChecker}.
 */
#[AsController]
class EditorStateController extends AppController
{
	/**
	 * @param iterable<EditorSettingsHandlerInterface> $settingsHandlers
	 */
	public function __construct(
		Security $security,
		ContainerInterface $container,
		#[AutowireIterator('iikiti.cms.editor_settings_handler')]
		private readonly iterable $settingsHandlers = [],
	) {
		parent::__construct($security, $container);
	}

	#[Route('/api/editor/css/validate', name: 'api_editor_css_validate', methods: ['POST'])]
	public function validateCustomCss(Request $request, PermissionChecker $permissionChecker, CustomCssCompilerInterface $cssCompiler): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$this->can($user, $permissionChecker, 'template', 'write')) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		$source = $this->payload($request)['css'] ?? null;
		if (!is_string($source)) {
			return $this->json(['error' => 'CSS source must be a string'], Response::HTTP_BAD_REQUEST);
		}

		try {
			$compiled = $cssCompiler->validate($source);

			return $this->json(['valid' => true, 'compiled' => $compiled]);
		} catch (\InvalidArgumentException $error) {
			return $this->json(['valid' => false, 'error' => $error->getMessage()]);
		}
	}

	#[Route('/api/editor/context', name: 'api_editor_context', methods: ['GET'])]
	public function editorContext(Request $request, PermissionChecker $permissionChecker, BlockEditorComponent $blockEditor, IconResolver $iconResolver): JsonResponse
	{
		$user = $this->getUser();
		$payload = $this->payload($request);
		$type = (string) ($payload['contextType'] ?? $request->query->get('contextType') ?? '');

		return $this->json([
			'contextType' => $type,
			'canEdit' => $this->can($user, $permissionChecker, $type, 'write'),
			'canPublish' => $this->can($user, $permissionChecker, $type, 'publish'),
			'blockTypes' => $blockEditor->getBlockTypes(),
			// Server-generated icon markup so the canvas renders exactly what the public site does.
			'iconSvgs' => $this->iconSvgs($iconResolver),
			'iconGlyphs' => $this->iconGlyphs($iconResolver),
		]);
	}

	/**
	 * @return array<string, string> icon name => SVG markup at container size
	 */
	private function iconSvgs(IconResolver $iconResolver): array
	{
		$svgs = [];
		foreach ($iconResolver->allReferences() as $reference) {
			$resolved = $iconResolver->resolve($reference);
			if (null === $resolved) {
				continue;
			}
			$svg = $resolved['set']->svg($resolved['name']);
			if (null !== $svg) {
				$svgs[$reference] = $svg;
			}
		}

		return $svgs;
	}

	/**
	 * Font glyph references (numeric character references) for icons that have one.
	 *
	 * @return array<string, string> icon reference => glyph markup
	 */
	private function iconGlyphs(IconResolver $iconResolver): array
	{
		$glyphs = [];
		foreach ($iconResolver->allReferences() as $reference) {
			$resolved = $iconResolver->resolve($reference);
			if (null === $resolved) {
				continue;
			}
			$glyph = $resolved['set']->glyph($resolved['name']);
			if (null !== $glyph) {
				$glyphs[$reference] = $glyph;
			}
		}

		return $glyphs;
	}

	#[Route('/api/editor/save', name: 'api_editor_save', methods: ['POST'])]
	public function save(
		Request $request,
		SaveWorkflowRegistry $workflows,
		PermissionChecker $permissionChecker,
	): JsonResponse {
		$user = $this->getUser();
		[$type, $id, $forbidden] = $this->resolveContext($user, $request, $permissionChecker, 'write');
		if (null !== $forbidden || !$user instanceof User) {
			return $forbidden ?? $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		$payload = $this->payload($request);
		$tree = is_array($payload['tree'] ?? null) ? $this->stripCssPreviewData($payload['tree']) : [];

		$result = $workflows->default()->save($type, (int) $id, $tree, $request->headers->get('If-Match'), $user);

		return $this->json($result, $result['conflict'] ? Response::HTTP_CONFLICT : Response::HTTP_OK);
	}

	#[Route('/api/editor/publish', name: 'api_editor_publish', methods: ['POST'])]
	public function publish(
		Request $request,
		SaveWorkflowRegistry $workflows,
		PermissionChecker $permissionChecker,
	): JsonResponse {
		$user = $this->getUser();
		[$type, $id, $forbidden] = $this->resolveContext($user, $request, $permissionChecker, 'publish');
		if (null !== $forbidden || !$user instanceof User) {
			return $forbidden ?? $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}

		$result = $workflows->default()->publish($type, (int) $id, $user);

		return $this->json($result);
	}

	#[Route('/api/editor/settings/save', name: 'api_editor_settings_save', methods: ['POST'])]
	public function saveSettings(
		Request $request,
		PermissionChecker $permissionChecker,
		EditorSettingsManager $settingsManager,
		WorkflowAccessPolicy $workflowAccessPolicy,
		?AuditRecorder $auditRecorder = null,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		$payload = $this->payload($request);
		[$targetType, $targetId, $badRequest] = $this->resolveSettingsTarget($payload);
		if (null !== $badRequest) {
			return $badRequest;
		}
		$customHandler = $this->findHandlerForTarget($targetType);
		if (!in_array($targetType, ['page', 'template'], true) && null === $customHandler) {
			return $this->json(['error' => 'Settings target provider is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
		}
		[$target, $targetError] = $this->authorizeAndLoadSettingsTarget(
			$user,
			$permissionChecker,
			$settingsManager,
			$targetType,
			$targetId,
			'write',
		);
		if (null !== $targetError || null === $target) {
			return $targetError ?? $this->json(['error' => 'Settings target not found'], Response::HTTP_NOT_FOUND);
		}
		$values = $payload['settings'] ?? null;
		if (!is_array($values)) {
			return $this->json(['error' => 'Settings must be an object'], Response::HTTP_BAD_REQUEST);
		}
		$currentState = $settingsManager->state($target);
		foreach ($this->settingsHandlers as $handler) {
			try {
				$values = $handler->prepareSaveValues($targetType, $target, $values, $currentState);
			} catch (\InvalidArgumentException $exception) {
				return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
			}
		}
		$version = $payload['version'] ?? $request->headers->get('If-Match');
		if (null !== $version && (!is_scalar($version) || !ctype_digit((string) $version))) {
			return $this->json(['error' => 'Invalid settings version'], Response::HTTP_BAD_REQUEST);
		}

		$result = $settingsManager->save($target, $values, null === $version ? null : (string) $version);
		if ($result['ok']) {
			$auditRecorder?->record(
				summary: sprintf('Saved %s settings draft for #%d', $targetType, $targetId),
				action: 'updated',
				objectType: $this->auditObjectType($targetType),
				objectId: $targetId,
				details: ['targetType' => $targetType, 'version' => $result['version'], 'keys' => array_keys($values)],
			);
		}

		return $this->json($result, $result['conflict'] ? Response::HTTP_CONFLICT : Response::HTTP_OK);
	}

	#[Route('/api/editor/settings/publish', name: 'api_editor_settings_publish', methods: ['POST'])]
	public function publishSettings(
		Request $request,
		PermissionChecker $permissionChecker,
		EditorSettingsManager $settingsManager,
		WorkflowAccessPolicy $workflowAccessPolicy,
		?AuditRecorder $auditRecorder = null,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		$payload = $this->payload($request);
		[$targetType, $targetId, $badRequest] = $this->resolveSettingsTarget($payload);
		if (null !== $badRequest) {
			return $badRequest;
		}
		$customHandler = $this->findHandlerForTarget($targetType);
		if (!in_array($targetType, ['page', 'template'], true) && null === $customHandler) {
			return $this->json(['error' => 'Settings target provider is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
		}
		[$target, $targetError] = $this->authorizeAndLoadSettingsTarget(
			$user,
			$permissionChecker,
			$settingsManager,
			$targetType,
			$targetId,
			'publish',
		);
		if (null !== $targetError || null === $target) {
			return $targetError ?? $this->json(['error' => 'Settings target not found'], Response::HTTP_NOT_FOUND);
		}
		$version = $payload['version'] ?? $request->headers->get('If-Match');
		if (null !== $version && (!is_scalar($version) || !ctype_digit((string) $version))) {
			return $this->json(['error' => 'Invalid settings version'], Response::HTTP_BAD_REQUEST);
		}
		$ifMatch = null === $version ? null : (string) $version;
		$state = $settingsManager->state($target);
		foreach ($this->settingsHandlers as $handler) {
			$errorResponse = $handler->validateAndPreparePublish($targetType, $target, $state, $ifMatch);
			if (null !== $errorResponse) {
				return $errorResponse;
			}
		}

		$result = $settingsManager->publish($target, $ifMatch);
		if ($result['ok']) {
			$auditRecorder?->record(
				summary: sprintf('Published %s settings for #%d', $targetType, $targetId),
				action: 'updated_configuration',
				objectType: $this->auditObjectType($targetType),
				objectId: $targetId,
				details: ['targetType' => $targetType, 'version' => $result['version']],
			);
		}

		return $this->json($result, $result['conflict'] ? Response::HTTP_CONFLICT : Response::HTTP_OK);
	}

	#[Route('/api/editor/render-block', name: 'api_editor_render_block', methods: ['POST'])]
	public function renderBlockFragment(Request $request, BlockRenderer $blockRenderer): Response
	{
		if (!$this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
			return new Response('', Response::HTTP_UNAUTHORIZED);
		}
		$payload = $this->payload($request);
		$node = is_array($payload['block'] ?? null) ? (array) $payload['block'] : ['type' => 'unknown'];
		$html = $blockRenderer->renderNode($node, new BlockRenderContext(editorMode: true, canEdit: true));

		return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	/**
	 * Mappable query-result fields for the block editor's binding picker:
	 * reflected object fields, whitelisted property columns, related-entity
	 * scalar fields and plugin-registered field functions.
	 */
	#[Route('/api/editor/query-fields', name: 'api_editor_query_fields', methods: ['GET'])]
	public function queryFields(
		Request $request,
		PermissionChecker $permissionChecker,
		QueryFieldCatalog $catalog,
	): JsonResponse {
		$user = $this->getUser();
		if (!$user instanceof User || (
			!$this->can($user, $permissionChecker, 'template', 'write') &&
			!$this->can($user, $permissionChecker, 'page', 'write')
		)) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		$objectType = trim((string) $request->query->get('objectType', ''));

		return $this->json(['fields' => $catalog->fields('' !== $objectType ? $objectType : null)]);
	}

	/**
	 * @return array{string, int|string, JsonResponse|null} [contextType, contextId, forbidden|null]
	 */
	private function resolveContext(
		?UserInterface $user,
		Request $request,
		PermissionChecker $pc,
		string $action,
	): array {
		if (null === $user) {
			return ['', $request->query->get('contextId'), $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN)];
		}
		$payload = $this->payload($request);
		$type = (string) ($payload['contextType'] ?? $request->query->get('contextType') ?? '');
		$id = $payload['contextId'] ?? $request->query->get('contextId');

		if (!$this->can($user, $pc, $type, $action)) {
			return [$type, $id, $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN)];
		}

		return [$type, $id, null];
	}

	private function can(?UserInterface $user, PermissionChecker $pc, string $type, string $action): bool
	{
		if (!$user instanceof User) {
			return false;
		}

		// Mirrors FrontendConfigProvider::canEdit(): editing a page's content goes
		// through its template, so Page write (the editor bootstrap gate) must be
		// sufficient here too — otherwise editors boot into the editor and then
		// every save 403s.
		if ('template' === $type) {
			return $pc->canAccess($user, 'Template', $action) ||
				$pc->canAccess($user, 'Page', $action);
		}

		return $pc->canAccess($user, 'Page', $action);
	}

	/**
	 * @return array{DbObject|null,JsonResponse|null}
	 */
	private function authorizeAndLoadSettingsTarget(
		User $user,
		PermissionChecker $pc,
		EditorSettingsManager $settingsManager,
		string $targetType,
		int $targetId,
		string $action,
	): array {
		$handler = $this->findHandlerForTarget($targetType);
		if (null !== $handler) {
			return $handler->authorizeAndLoadTarget($targetType, $targetId, $user, $action);
		}

		$target = $settingsManager->findTarget($targetType, $targetId);
		if (null === $target) {
			return [null, $this->json(['error' => 'Settings target not found'], Response::HTTP_NOT_FOUND)];
		}
		if (!$this->canSettingsTarget($user, $pc, $targetType, $action, $target)) {
			return [null, $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN)];
		}

		return [$target, null];
	}

	private function canSettingsTarget(
		User $user,
		PermissionChecker $pc,
		string $type,
		string $action,
		DbObject $target,
	): bool {
		if ('template' === $type) {
			return $pc->canAccess($user, 'Template', $action, $target) ||
				$pc->canAccess($user, 'Page', $action, $target);
		}

		return $pc->canAccess($user, 'Page', $action, $target);
	}

	private function auditObjectType(string $targetType): string
	{
		$handler = $this->findHandlerForTarget($targetType);
		if (null !== $handler) {
			$type = $handler->auditObjectType($targetType);
			if (null !== $type && '' !== $type) {
				return $type;
			}
		}

		return match ($targetType) {
			'template' => 'Template',
			default => 'Page',
		};
	}

	private function findHandlerForTarget(string $targetType): ?EditorSettingsHandlerInterface
	{
		foreach ($this->settingsHandlers as $handler) {
			if ($handler->supportsTarget($targetType)) {
				return $handler;
			}
		}

		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload(Request $request): array
	{
		$decoded = json_decode((string) $request->getContent(), true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<mixed> $value
	 *
	 * @return array<mixed>
	 */
	private function stripCssPreviewData(array $value): array
	{
		foreach ($value as $key => $item) {
			if ('customCssCompiled' === $key) {
				unset($value[$key]);
			} elseif (is_array($item)) {
				$value[$key] = $this->stripCssPreviewData($item);
			}
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $payload
	 *
	 * @return array{string,int,JsonResponse|null}
	 */
	private function resolveSettingsTarget(array $payload): array
	{
		$type = $payload['targetType'] ?? null;
		$id = $payload['targetId'] ?? null;
		$validatedId = is_int($id) || is_string($id) ?
			filter_var((string) $id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) :
			false;
		$supportedTypes = ['page', 'template'];
		if (is_string($type) && null !== $this->findHandlerForTarget($type)) {
			$supportedTypes[] = $type;
		}
		if (!in_array($type, $supportedTypes, true) || false === $validatedId) {
			return ['', 0, $this->json(['error' => 'Invalid settings target'], Response::HTTP_BAD_REQUEST)];
		}

		return [$type, (int) $validatedId, null];
	}
}
