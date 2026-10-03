<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\BlockEditorComponent;
use iikiti\CMS\Web\BlockEditor\Usage\BlockUsageIndexInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Block-widget enumeration and usage endpoints backing the eventual
 * "registered block widgets" admin dashboard.
 */
#[AsController]
#[Route('/api/admin/block-widgets', name: 'api_admin_block_widgets_')]
final class BlockWidgetController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(BlockEditorComponent $blockEditor, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canRead($permissionChecker)) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}

		return $this->json(['blockWidgets' => $blockEditor->getBlockTypes()]);
	}

	#[Route('/usage', name: 'usage', methods: ['GET'])]
	public function usage(BlockUsageIndexInterface $usageIndex, PermissionChecker $permissionChecker): JsonResponse
	{
		if (!$this->canRead($permissionChecker)) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}

		return $this->json(['usage' => $usageIndex->usage()]);
	}

	private function canRead(PermissionChecker $permissionChecker): bool
	{
		$user = $this->getUser();
		if (!$user instanceof User) {
			return false;
		}

		return $permissionChecker->canAccess($user, 'Template', 'write') ||
			$permissionChecker->canAccess($user, 'Page', 'write');
	}
}
