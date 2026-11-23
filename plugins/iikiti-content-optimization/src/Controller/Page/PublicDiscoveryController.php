<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Controller\Page;

use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use iikiti\Extension\ContentOptimization\Service\PublicDiscoveryDocumentGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PublicDiscoveryController
{
	public function __construct(
		private PublicPageApiProxy $apiProxy,
		private PublicDiscoveryDocumentGenerator $documents,
		#[Autowire('%application_role%')]
		private string $applicationRole,
	) {
	}

	#[Route('/robots.txt', name: 'public_robots', methods: ['GET', 'HEAD'])]
	public function robots(Request $request): Response
	{
		return 'consumer' === $this->applicationRole ?
			$this->apiProxy->robots($request) :
			$this->response($this->documents->robots($request));
	}

	#[Route('/sitemap.xml', name: 'public_sitemap', methods: ['GET', 'HEAD'])]
	public function sitemap(Request $request): Response
	{
		return 'consumer' === $this->applicationRole ?
			$this->apiProxy->sitemap($request) :
			$this->response($this->documents->sitemap($request));
	}

	/** @param array{body:string,contentType:string} $document */
	private function response(array $document): Response
	{
		return new Response($document['body'], Response::HTTP_OK, [
			'Content-Type' => $document['contentType'],
			'Cache-Control' => 'public, max-age=300',
		]);
	}
}
