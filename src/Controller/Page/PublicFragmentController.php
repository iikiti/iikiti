<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Same-origin access to explicitly public plugin fragments. */
#[AsController]
final readonly class PublicFragmentController
{
	public function __construct(
		#[AutowireServiceClosure(FragmentRuntime::class)]
		private \Closure $fragmentRuntime,
		private PublicPageApiProxy $apiProxy,
		#[Autowire('%application_role%')]
		private string $applicationRole,
	) {
	}

	#[Route('/_iikiti/fragments/{provider}', name: 'public_fragment', requirements: ['provider' => '[a-z0-9][a-z0-9-]{0,62}\\.[a-z][a-z0-9_.-]{0,63}'], methods: ['GET', 'HEAD'])]
	public function __invoke(Request $request, string $provider): Response
	{
		if ('consumer' === $this->applicationRole) {
			return $this->apiProxy->fragment($request, $provider);
		}

		$params = $this->params($request);
		$result = ($this->fragmentRuntime)()->render($provider, new FragmentContext(
			SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null,
			(string) $request->headers->get('X-SITE-LOCALE', $request->getLocale()),
			$params,
		));

		return new JsonResponse($result->toArray(), Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
	}

	/** @return array<string,mixed> */
	private function params(Request $request): array
	{
		$encoded = $request->query->get('params', '{}');
		if (strlen($encoded) > 16_384) {
			throw new BadRequestHttpException('Fragment parameters are invalid or too large.');
		}
		try {
			$params = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new BadRequestHttpException('Fragment parameters must be a JSON object.');
		}
		if (!is_array($params) || (array_is_list($params) && [] !== $params)) {
			throw new BadRequestHttpException('Fragment parameters must be a JSON object.');
		}

		return $params;
	}
}
