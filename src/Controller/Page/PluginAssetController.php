<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Plugin\PluginRegistry;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves admin UI bundle assets from active plugins.
 *
 * Files are served from `cms/extensions/active/{slug}/public/` so that plugin
 * UI bundles (compiled Svelte/TS) can be dynamically imported by the admin SPA.
 */
#[AsController]
#[IsGranted('ROLE_ADMIN')]
class PluginAssetController extends AppController
{
	private const PUBLIC_SUBDIR = 'public';

	#[Route('/admin-plugins/{slug}/{path}', name: 'admin_plugin_asset', requirements: ['path' => '.+'])]
	public function asset(string $slug, string $path, PluginRegistry $pluginRegistry): Response
	{
		$pluginPath = $pluginRegistry->getPath($slug);

		if (null === $pluginPath) {
			throw $this->createNotFoundException(sprintf('Plugin "%s" is not active.', $slug));
		}

		$publicDir = $pluginPath.'/'.self::PUBLIC_SUBDIR;
		$requestedFile = Path::join($publicDir, $path);

		$realPublic = realpath($publicDir);
		if (false === $realPublic) {
			throw $this->createNotFoundException(sprintf('Public directory not found for plugin "%s".', $slug));
		}

		$realFile = realpath($requestedFile);

		if (false === $realFile || !str_starts_with($realFile, $realPublic)) {
			throw $this->createNotFoundException('File not found.');
		}

		if (!is_file($realFile)) {
			throw $this->createNotFoundException('File not found.');
		}

		return new BinaryFileResponse($realFile);
	}
}
