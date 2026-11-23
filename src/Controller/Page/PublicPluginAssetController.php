<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Web\BlockEditor\PublicAssetApiProxy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves public-facing ES module and stylesheet assets shipped in active plugins'
 * `public/` directories (with Consumer-mode proxy fallback to the API runtime).
 */
#[AsController]
final class PublicPluginAssetController extends AbstractController
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

	public function __construct(
		private readonly PluginRegistry $pluginRegistry,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole = 'all',
		private readonly ?PublicAssetApiProxy $apiProxy = null,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[Route('/plugin-assets/{slug}/{path}', name: 'public_plugin_asset', requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,63}', 'path' => '.+'], methods: ['GET', 'HEAD'])]
	#[Route('/api/public/plugin-assets/{slug}/{path}', name: 'api_public_plugin_asset', requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,63}', 'path' => '.+'], methods: ['GET', 'HEAD'])]
	public function asset(Request $request, string $slug, string $path): Response
	{
		$normalizedPath = $this->validateAssetPath($slug, $path);
		if (null === $normalizedPath) {
			throw $this->createNotFoundException('Invalid plugin asset path.');
		}

		if ('consumer' !== $this->applicationRole && null !== $this->activationChecker && !$this->activationChecker->isActive($slug)) {
			throw $this->createNotFoundException(sprintf('Plugin "%s" is not active.', $slug));
		}

		$extension = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
		$mime = self::EXTENSION_MIME_TYPES[$extension] ?? null;
		if (null === $mime) {
			throw $this->createNotFoundException(sprintf('Unsupported asset type for "%s".', $normalizedPath));
		}

		$pluginPath = $this->pluginRegistry->getPath($slug);
		if (null !== $pluginPath) {
			$publicDir = $pluginPath.'/'.self::PUBLIC_SUBDIR;
			$realPublic = realpath($publicDir);
			if (false !== $realPublic) {
				$realFile = realpath(Path::join($publicDir, $normalizedPath));
				$publicPrefix = rtrim($realPublic, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
				if (false !== $realFile && str_starts_with($realFile, $publicPrefix) && is_file($realFile)) {
					$response = new BinaryFileResponse($realFile);
					$response->headers->set('X-Content-Type-Options', 'nosniff');
					$response->headers->set('Cache-Control', 'public, max-age=3600');
					if ('svg' === $extension) {
						$response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
					}
					$response->headers->set('Content-Type', $mime);

					return $response;
				}
			}
		}

		if ('consumer' === $this->applicationRole && null !== $this->apiProxy) {
			return $this->apiProxy->pluginAsset($request, $slug, $normalizedPath);
		}

		throw $this->createNotFoundException(sprintf('Asset "%s" not found for plugin "%s".', $normalizedPath, $slug));
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
