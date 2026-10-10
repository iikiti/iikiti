<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\Icon\IconSetManager;
use iikiti\CMS\Web\Icon\SvgRejectedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for managed icon sets and their sanitised SVG icons.
 *
 * Reads need the same access as the block-widget admin (template or page write).
 * Writes need template write, since icon sets are design assets. Every upload is
 * sanitised by {@see \iikiti\CMS\Web\Icon\SvgSanitizer} before it is stored; a rejected
 * SVG returns 422 and nothing is saved.
 */
#[AsController]
#[Route('/api/admin/icon-sets', name: 'api_admin_icon_sets_')]
final class IconSetController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canRead($permissionChecker)) {
			return $this->forbidden();
		}

		$sets = array_map(fn (IconSetEntity $set): array => $this->serialiseSet($manager, $set), $manager->allSets());

		return $this->json(['sets' => $sets]);
	}

	#[Route('', name: 'create', methods: ['POST'])]
	public function create(Request $request, IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canWrite($permissionChecker)) {
			return $this->forbidden();
		}

		$payload = $this->payload($request);
		try {
			$set = $manager->createSet((string) ($payload['slug'] ?? ''), (string) ($payload['name'] ?? ''));
		} catch (\InvalidArgumentException $e) {
			return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($this->serialiseSet($manager, $set), Response::HTTP_CREATED);
	}

	#[Route('/{slug}', name: 'show', methods: ['GET'])]
	public function show(string $slug, IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canRead($permissionChecker)) {
			return $this->forbidden();
		}

		$set = $manager->findSet($slug);
		if (null === $set) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json([
			...$this->serialiseSet($manager, $set),
			'icons' => array_map(
				static fn ($icon): array => ['name' => $icon->getName(), 'svg' => $icon->getSvg()],
				$manager->iconsIn($set),
			),
		]);
	}

	#[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
	public function delete(string $slug, IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canWrite($permissionChecker)) {
			return $this->forbidden();
		}

		$set = $manager->findSet($slug);
		if (null === $set) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		$manager->deleteSet($set);

		return $this->json(['deleted' => true]);
	}

	#[Route('/{slug}/icons/{name}', name: 'put_icon', methods: ['PUT'])]
	public function putIcon(string $slug, string $name, Request $request, IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canWrite($permissionChecker)) {
			return $this->forbidden();
		}

		$set = $manager->findSet($slug);
		if (null === $set) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		$svg = (string) ($this->payload($request)['svg'] ?? '');
		try {
			$icon = $manager->putIcon($set, $name, $svg);
		} catch (SvgRejectedException $e) {
			return $this->json(['error' => 'SVG rejected', 'reason' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (\InvalidArgumentException $e) {
			return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json(['name' => $icon->getName(), 'reference' => $slug.'/'.$icon->getName()], Response::HTTP_OK);
	}

	#[Route('/{slug}/icons/{name}', name: 'delete_icon', methods: ['DELETE'])]
	public function deleteIcon(string $slug, string $name, IconSetManager $manager, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canWrite($permissionChecker)) {
			return $this->forbidden();
		}

		$set = $manager->findSet($slug);
		if (null === $set) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->json(['deleted' => $manager->removeIcon($set, $name)]);
	}

	/**
	 * @return array{slug: string, name: string, iconCount: int}
	 */
	private function serialiseSet(IconSetManager $manager, IconSetEntity $set): array
	{
		return [
			'slug' => $set->getSlug(),
			'name' => $set->getName(),
			'iconCount' => count($manager->iconsIn($set)),
		];
	}

	private function canRead(PermissionChecker $permissionChecker): bool
	{
		return $this->canWrite($permissionChecker) || $this->hasPermission($permissionChecker, 'Page', 'write');
	}

	private function canWrite(PermissionChecker $permissionChecker): bool
	{
		return $this->hasPermission($permissionChecker, 'Template', 'write');
	}

	private function hasPermission(PermissionChecker $permissionChecker, string $object, string $action): bool
	{
		$user = $this->getUser();
		if (!$user instanceof User) {
			return false;
		}

		return $permissionChecker->canAccess($user, $object, $action);
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}

	/**
	 * JSON request body as an array; anything that is not a JSON object yields [].
	 *
	 * @return array<string, mixed>
	 */
	private function payload(Request $request): array
	{
		$decoded = json_decode((string) $request->getContent(), true);

		return is_array($decoded) ? $decoded : [];
	}
}
