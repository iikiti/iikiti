<?php

namespace iikiti\CMS\Controller\API\V1;

use iikiti\CMS\Controller\API\APIController;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Page;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Object API controller.
 */
#[AsController]
#[Route('/api/v1/objects', name: 'api_v1_objects_')]
class ObjectApiController extends AppController
{
	public function __construct(private APIController $api)
	{
	}

	#[Route('', name: 'list', methods: ['GET'])]
	public function index(): JsonResponse
	{
		$pages = $this->api->getObject(
			typeClass: \iikiti\CMS\Entity\Object\Page::class
		);

		return $this->json($pages);
	}

	#[Route('/{id}', name: 'show', methods: ['GET'])]
	public function show(int $id): JsonResponse
	{
		$page = $this->api->getObject(
			typeClass: Page::class,
			criteria: ['id' => $id],
			limit: 1
		);

		if (empty($page)) {
			return $this->json(['message' => 'Page not found'], 404);
		}

		return $this->json($page[0]);
	}

	#[Route('', name: 'create', methods: ['POST'])]
	public function create(Request $request, SerializerInterface $serializer): JsonResponse
	{
		/** @var Page $page */
		$page = $serializer->deserialize($request->getContent(), Page::class, 'json');

		$this->api->createObject($page);

		return $this->json($page, 201);
	}

	#[Route('/{id}', name: 'update', methods: ['PUT'])]
	public function update(int $id, Request $request, SerializerInterface $serializer): JsonResponse
	{
		$existingPage = $this->api->getObject(
			typeClass: Page::class,
			criteria: ['id' => $id],
			limit: 1
		);

		if (empty($existingPage)) {
			return $this->json(['message' => 'Page not found'], 404);
		}

		$page = $serializer->deserialize(
			$request->getContent(),
			Page::class,
			'json',
			['object_to_populate' => $existingPage[0]]
		);

		$this->api->updateObject($page);

		return $this->json($page);
	}

	#[Route('/{id}', name: 'delete', methods: ['DELETE'])]
	public function delete(int $id): JsonResponse
	{
		$page = $this->api->getObject(
			typeClass: Page::class,
			criteria: ['id' => $id],
			limit: 1
		);

		if (empty($page)) {
			return $this->json(['message' => 'Page not found'], 404);
		}

		$this->api->deleteObject($page[0]);

		return $this->json(null, 204);
	}
}