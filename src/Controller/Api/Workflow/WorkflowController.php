<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Workflow;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\MultiStepFormRunner;
use iikiti\CMS\Web\Workflow\WorkflowSchemaExtractor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes the current step of a session-based form workflow (e.g. MFA, multistep
 * forms) as a JSON field schema so the front-end workflow engine can render it.
 *
 * Served from the main (session) firewall — not the /api token firewall — so it
 * is reachable during an in-progress MFA challenge. Plugins can register
 * additional flows via the flow map (a tagged `WorkflowProviderInterface` is the
 * planned extension point).
 */
#[AsController]
class WorkflowController extends AppController
{
	/** Flow name => session key holding the persisted workflow state. */
	private const FLOWS = [
		'mfa' => 'mfa_challenge',
		'multistep' => 'multistep_form',
	];

	public function __construct(
		private readonly MultiStepFormRunner $runner,
		private readonly WorkflowSchemaExtractor $extractor,
	) {
	}

	#[Route('/flow/{flow}/step', name: 'api_workflows_step', methods: ['GET'])]
	public function step(string $flow): JsonResponse
	{
		if (!$this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
			return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
		}

		$sessionKey = self::FLOWS[$flow] ?? null;
		if (null === $sessionKey) {
			return $this->json(['error' => 'Unknown workflow'], Response::HTTP_NOT_FOUND);
		}

		$workflow = $this->runner->load($sessionKey);
		if (null === $workflow) {
			return $this->json(['active' => false], Response::HTTP_OK);
		}

		$step = $workflow->getCurrentStep();
		$fields = [];
		if ($step instanceof FormStepInterface) {
			$form = $this->runner->createForm($workflow, $step);
			$fields = $this->extractor->extract($form);
		}

		return $this->json([
			'active' => true,
			'flow' => $flow,
			'step' => $step ? $step->getId() : null,
			'stepIndex' => $workflow->getCurrentStepIndex(),
			'stepCount' => count($workflow->getSteps()),
			'complete' => $workflow->isComplete(),
			'fields' => $fields,
		]);
	}
}
