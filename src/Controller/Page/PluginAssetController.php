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
 * Files are served from `{pluginPath}/public/` so that plugin UI bundles
 * (compiled Svelte/TS ES modules) can be dynamically imported by the admin SPA
 * and block editor.
 */
#[AsController]
#[IsGranted('ROLE_ADMIN')]
class PluginAssetController extends AppController
{
	private const PUBLIC_SUBDIR = 'public';

	/** @var array<string,string> */
	private const EXTENSION_MIME_TYPES = [
		'js' => 'application/javascript; charset=UTF-8',
		'mjs' => 'application/javascript; charset=UTF-8',
		'css' => 'text/css; charset=UTF-8',
		'json' => 'application/json; charset=UTF-8',
		'map' => 'application/json; charset=UTF-8',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'ico' => 'image/x-icon',
		'woff2' => 'font/woff2',
		'woff' => 'font/woff',
		'ttf' => 'font/ttf',
		'otf' => 'font/otf',
		'eot' => 'application/vnd.ms-fontobject',
		'txt' => 'text/plain; charset=UTF-8',
	];

	#[Route('/admin-plugins/{slug}/{path}', name: 'admin_plugin_asset', requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,63}', 'path' => '.+'])]
	public function asset(string $slug, string $path, PluginRegistry $pluginRegistry): Response
	{
		$normalizedPath = $this->validateAssetPath($slug, $path);
		if (null === $normalizedPath) {
			throw $this->createNotFoundException('File not found.');
		}

		$extension = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
		$mime = self::EXTENSION_MIME_TYPES[$extension] ?? null;
		if (null === $mime) {
			throw $this->createNotFoundException('File not found.');
		}

		$pluginPath = $pluginRegistry->getPath($slug);

		if (null === $pluginPath) {
			throw $this->createNotFoundException(sprintf('Plugin "%s" is not active.', $slug));
		}

		$publicDir = $pluginPath.'/'.self::PUBLIC_SUBDIR;
		$requestedFile = Path::join($publicDir, $normalizedPath);

		$realPublic = realpath($publicDir);
		if (false === $realPublic) {
			throw $this->createNotFoundException(sprintf('Public directory not found for plugin "%s".', $slug));
		}

		$realFile = realpath($requestedFile);
		$publicPrefix = rtrim($realPublic, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

		if (false === $realFile || !str_starts_with($realFile, $publicPrefix)) {
			throw $this->createNotFoundException('File not found.');
		}

		if (!is_file($realFile)) {
			throw $this->createNotFoundException('File not found.');
		}

		$response = new BinaryFileResponse($realFile);
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		if ('svg' === $extension) {
			$response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
		}
		$response->headers->set('Content-Type', $mime);

		return $response;
	}

	private function validateAssetPath(string $slug, string $path): ?string
	{
		if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) {
			return null;
		}
		if ('' === $path || str_starts_with($path, '/') || 1 === preg_match('/[\x00-\x1f\x7f\\\\?#]/', $path)) {
			return null;
		}

		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ('' === $segment || '.' === $segment || '..' === $segment || str_starts_with($segment, '.')) {
				return null;
			}
		}

		return implode('/', $segments);
	}
}
