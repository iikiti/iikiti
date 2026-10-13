<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/asset-workflows', name: 'api_admin_asset_workflows_')]
final class AssetWorkflowController extends AppController
{
	#[Route('', name: 'index', methods: ['GET'])]
	public function index(EntityManagerInterface $entityManager, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$records = $entityManager->getRepository(AssetWorkflowDefinition::class)->findBy([], ['id' => 'ASC']);

		return $this->json(['workflows' => array_map($this->serialise(...), $records)]);
	}

	#[Route('', name: 'create', methods: ['POST'])]
	public function create(Request $request, EntityManagerInterface $entityManager, DigitalAssetProcessorRegistry $processors, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$payload = $this->payload($request);
		try {
			$this->validatePayload($payload, $processors);
			$workflow = new AssetWorkflowDefinition(
				(string) $payload['key'],
				(string) $payload['name'],
				(string) $payload['processorKey'],
				(string) $payload['mimePattern'],
				$payload['conditions'] ?? [],
				$payload['options'] ?? [],
			);
			$workflow->setEnabled((bool) ($payload['enabled'] ?? true));
			$entityManager->persist($workflow);
			$entityManager->flush();
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($this->serialise($workflow), Response::HTTP_CREATED);
	}

	#[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function update(int $id, Request $request, EntityManagerInterface $entityManager, DigitalAssetProcessorRegistry $processors, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$workflow = $entityManager->find(AssetWorkflowDefinition::class, $id);
		if (!$workflow instanceof AssetWorkflowDefinition) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		$payload = $this->payload($request);
		try {
			$this->validatePayload($payload, $processors);
			$workflow->
				setKey((string) $payload['key'])->
				setName((string) $payload['name'])->
				setProcessorKey((string) $payload['processorKey'])->
				setMimePattern((string) $payload['mimePattern'])->
				setConditions($payload['conditions'] ?? [])->
				setOptions($payload['options'] ?? [])->
				setEnabled((bool) ($payload['enabled'] ?? true));
			$entityManager->flush();
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		return $this->json($this->serialise($workflow));
	}

	#[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
	public function delete(int $id, EntityManagerInterface $entityManager, DigitalAssetAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User || !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$workflow = $entityManager->find(AssetWorkflowDefinition::class, $id);
		if (!$workflow instanceof AssetWorkflowDefinition) {
			return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
		}
		$entityManager->remove($workflow);
		$entityManager->flush();

		return $this->json(['deleted' => true]);
	}

	/** @param array<string,mixed> $payload */
	private function validatePayload(array $payload, DigitalAssetProcessorRegistry $processors): void
	{
		foreach (['key', 'name', 'processorKey', 'mimePattern'] as $required) {
			if (!isset($payload[$required]) || !is_string($payload[$required])) {
				throw new \InvalidArgumentException(sprintf('Workflow field "%s" is required.', $required));
			}
		}
		if (!$processors->has($payload['processorKey'])) {
			throw new \InvalidArgumentException('The selected workflow processor is not registered.');
		}
		if (!$processors->get($payload['processorKey'])->supportsMimePattern($payload['mimePattern'])) {
			throw new \InvalidArgumentException('The selected processor does not support this MIME pattern.');
		}
		foreach (['conditions', 'options'] as $field) {
			if (isset($payload[$field]) && !is_array($payload[$field])) {
				throw new \InvalidArgumentException(sprintf('Workflow %s must be an object.', $field));
			}
		}
		$json = json_encode([$payload['conditions'] ?? [], $payload['options'] ?? []]);
		if (!is_string($json) || strlen($json) > 16384) {
			throw new \InvalidArgumentException('Workflow conditions and options must not exceed 16 KB.');
		}
	}

	/** @return array<string,mixed> */
	private function serialise(AssetWorkflowDefinition $workflow): array
	{
		return [
			'id' => $workflow->getId(),
			'key' => $workflow->getKey(),
			'name' => $workflow->getName(),
			'processorKey' => $workflow->getProcessorKey(),
			'mimePattern' => $workflow->getMimePattern(),
			'conditions' => $workflow->getConditions(),
			'options' => $workflow->getOptions(),
			'enabled' => $workflow->isEnabled(),
		];
	}

	/** @return array<string,mixed> */
	private function payload(Request $request): array
	{
		$payload = json_decode((string) $request->getContent(), true);

		return is_array($payload) ? $payload : [];
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}
}
