<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Editor;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\BlockEditorComponent;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Workflow\SaveWorkflowRegistry;
use iikiti\CMS\Web\Icon\IconResolver;
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
		$tree = is_array($payload['tree'] ?? null) ? (array) $payload['tree'] : [];

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
			!$this->can($user, $permissionChecker, 'template', 'write')
			&& !$this->can($user, $permissionChecker, 'page', 'write')
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
	 * @return array<string,mixed>
	 */
	private function payload(Request $request): array
	{
		$decoded = json_decode((string) $request->getContent(), true);

		return is_array($decoded) ? $decoded : [];
	}
}
