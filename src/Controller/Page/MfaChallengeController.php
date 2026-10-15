<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Authentication\Mfa\MfaProofPolicy;
use iikiti\CMS\Authentication\Mfa\MfaWorkflowDefinitionResolver;
use iikiti\CMS\Authentication\Mfa\MfaWorkflowSnapshot;
use iikiti\CMS\Authentication\MfaAttemptLimiter;
use iikiti\CMS\Authentication\Token\AuthenticationToken;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\TargetPathStorage;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\MultiStepFormRunner;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\WorkflowInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Drives the multi-factor challenge as a workflow of form steps.
 *
 * Every request rebuilds the workflow from its persisted state, renders the
 * current step's form, and on submission advances the workflow. When the last
 * step succeeds the proxy token is marked authenticated.
 */
#[AsController]
class MfaChallengeController extends AppController
{
	public const WORKFLOW_NAME = MfaStepProvider::WORKFLOW_NAME;
	public const ROUTE = 'mfa_challenge';

	private const SESSION_KEY = 'mfa_challenge';
	private const TEMPLATE = 'mfa_challenge.twig';
	private const ERROR_MESSAGE = 'The code you entered is incorrect or has expired.';
	private const LOCKED_MESSAGE = 'Too many incorrect codes. Please sign in again.';

	public function __construct(
		Security $security,
		ContainerInterface $container,
		private readonly MultiStepFormRunner $runner,
		private readonly MfaWorkflowDefinitionResolver $workflowDefinitions,
		private readonly MfaProofPolicy $proofPolicy,
		private readonly TokenStorageInterface $tokenStorage,
		private readonly TargetPathStorage $targetPathStorage,
		private readonly MfaAttemptLimiter $attemptLimiter,
	) {
		parent::__construct($security, $container);
	}

	#[Route('/mfa/challenge', name: self::ROUTE, methods: ['GET', 'POST'])]
	public function challenge(Request $request): Response
	{
		$token = $this->security->getToken();
		if (!$token instanceof AuthenticationToken || $token->isAuthenticated()) {
			$this->runner->remove(self::SESSION_KEY);

			return $this->redirectToRoute('home');
		}

		$userIdentifier = $token->getUserIdentifier();
		if ($this->attemptLimiter->isLocked($userIdentifier)) {
			return $this->lockOut();
		}

		$user = $token->getUser();
		if (!$user instanceof User) {
			throw new \LogicException('Multi-factor authentication requires an identified user.');
		}
		$userId = $this->resolveUserId($token);
		$state = $this->runner->loadState(self::SESSION_KEY);
		$priorContext = $this->stateContextForUser($state, $userId);
		$snapshot = $this->resolveSnapshot($user, $priorContext);
		if (null === $snapshot) {
			return $this->blockInvalidConfiguration();
		}
		[$workflow, $restarted] = $this->resolveWorkflow($userId, $snapshot, $state);
		if (null === $workflow) {
			$snapshot = $this->resolveSnapshot($user);
			if (null === $snapshot) {
				return $this->blockInvalidConfiguration();
			}
			$workflow = $this->startWorkflow($token, $userId, $snapshot);
			$restarted = true;
		}
		if ($workflow->isComplete()) {
			$currentSnapshot = $this->resolveSnapshot($user);
			if (null === $currentSnapshot) {
				return $this->blockInvalidConfiguration();
			}
			if ($currentSnapshot->versionKey === $snapshot->versionKey &&
				$currentSnapshot->policyFingerprint === $snapshot->policyFingerprint &&
				$this->proofPolicy->isSatisfied($currentSnapshot->requiredProofs, $workflow->getContext())) {
				return $this->completeChallenge($token, $userIdentifier);
			}
			if ($currentSnapshot->versionKey !== $snapshot->versionKey || $currentSnapshot->policyFingerprint !== $snapshot->policyFingerprint) {
				return $this->restartChallenge($token);
			}

			$this->runner->remove(self::SESSION_KEY);
			$this->tokenStorage->setToken(null);
			$this->addFlash('error', 'The MFA challenge did not satisfy the current security policy. Please sign in again.');

			return $this->redirectToRoute('html_login');
		}
		$step = $this->requireFormStep($workflow);

		if ($request->isMethod(Request::METHOD_POST) && !$restarted) {
			return $this->handleSubmission($request, $workflow, $step, $token, $userIdentifier, $snapshot);
		}

		$this->runner->prepare(self::SESSION_KEY, $workflow, $step);
		if ($restarted && $request->isMethod(Request::METHOD_POST)) {
			$this->addFlash('info', 'The MFA workflow changed. Complete the refreshed challenge to continue.');
		}

		return $this->renderTemplate($workflow, $step);
	}

	/**
	 * @param array<string,mixed>|null $state
	 *
	 * @return array{WorkflowInterface|null,bool}
	 */
	private function resolveWorkflow(int|string $userId, MfaWorkflowSnapshot $snapshot, ?array $state): array
	{
		if (null !== $state && $this->stateBelongsTo($state, $userId, $snapshot)) {
			return [$this->runner->restore($state), false];
		}

		if (null !== $state) {
			$this->runner->remove(self::SESSION_KEY);
		}

		return [null, true];
	}

	private function startWorkflow(AuthenticationToken $token, int|string $userId, MfaWorkflowSnapshot $snapshot): WorkflowInterface
	{
		$user = $token->getUser();
		if (!$user instanceof User) {
			throw new \LogicException('Multi-factor authentication requires an identified user.');
		}

		return $this->runner->create(self::SESSION_KEY, self::WORKFLOW_NAME, $this->workflowDefinitions->createSessionContext($user, $snapshot));
	}

	private function resolveUserId(AuthenticationToken $token): int|string
	{
		$user = $token->getUser();
		if (!$user instanceof User || null === $user->getId()) {
			throw new \LogicException('Multi-factor authentication requires an identified user.');
		}

		return $user->getId();
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function stateBelongsTo(array $state, int|string $userId, MfaWorkflowSnapshot $snapshot): bool
	{
		$context = $state['context'] ?? null;
		if (!is_array($context)) {
			return false;
		}

		if (!$this->contextBelongsToUser($context, $userId)) {
			return false;
		}
		$initialBackupCodes = $context[MfaStepProvider::CONTEXT_BACKUP_CODES] ?? [];
		$usedBackupCodes = $context['mfa_backup_code_hashes_used'] ?? [];
		if (!is_array($initialBackupCodes) || !is_array($usedBackupCodes)) {
			return false;
		}
		foreach ([...$initialBackupCodes, ...$usedBackupCodes] as $backupCodeHash) {
			if (!is_string($backupCodeHash)) {
				return false;
			}
		}
		$expectedBackupCodes = array_values(array_diff($initialBackupCodes, $usedBackupCodes));
		$currentBackupCodes = $snapshot->configuration->getBackupCodeHashes();
		sort($expectedBackupCodes);
		sort($currentBackupCodes);

		return ($context[MfaStepProvider::CONTEXT_DEFINITION_VERSION] ?? null) === $snapshot->versionKey &&
			($context[MfaStepProvider::CONTEXT_POLICY_FINGERPRINT] ?? null) === $snapshot->policyFingerprint &&
			$expectedBackupCodes === $currentBackupCodes;
	}

	/** @param array<string,mixed>|null $state
	 * @return array<string,mixed>
	 */
	private function stateContextForUser(?array $state, int|string $userId): array
	{
		$context = $state['context'] ?? null;

		return is_array($context) && $this->contextBelongsToUser($context, $userId) ? $context : [];
	}

	/** @param array<string,mixed> $context */
	private function contextBelongsToUser(array $context, int|string $userId): bool
	{
		$storedUserId = $context['user_id'] ?? null;

		return (is_int($storedUserId) || is_string($storedUserId)) &&
			(string) $storedUserId === (string) $userId;
	}

	private function requireFormStep(WorkflowInterface $workflow): FormStepInterface
	{
		$step = $workflow->getCurrentStep();

		if (null === $step) {
			throw new \LogicException('The multi-factor workflow has no remaining steps.');
		}

		if (!$step instanceof FormStepInterface) {
			throw new \LogicException(sprintf('Workflow step "%s" does not render a form.', $step->getId()));
		}

		return $step;
	}

	private function handleSubmission(
		Request $request,
		WorkflowInterface $workflow,
		FormStepInterface $step,
		AuthenticationToken $token,
		string $userIdentifier,
		MfaWorkflowSnapshot $snapshot,
	): Response {
		$result = $this->runner->submitRequest(self::SESSION_KEY, $workflow, $step, $request);

		if ($result->accepted) {
			if ($workflow->isComplete()) {
				$user = $token->getUser();
				if (!$user instanceof User) {
					throw new \LogicException('Multi-factor authentication requires an identified user.');
				}
				$currentSnapshot = $this->resolveSnapshot($user);
				if (null === $currentSnapshot) {
					return $this->blockInvalidConfiguration();
				}
				if ($currentSnapshot->versionKey !== $snapshot->versionKey || $currentSnapshot->policyFingerprint !== $snapshot->policyFingerprint) {
					return $this->restartChallenge($token);
				}
				if (!$this->proofPolicy->isSatisfied($currentSnapshot->requiredProofs, $workflow->getContext())) {
					$this->runner->remove(self::SESSION_KEY);
					$this->tokenStorage->setToken(null);
					$this->addFlash('error', 'The MFA challenge did not satisfy the current security policy. Please sign in again.');

					return $this->redirectToRoute('html_login');
				}

				return $this->completeChallenge($token, $userIdentifier);
			}

			return $this->redirectToRoute(self::ROUTE);
		}

		// A structurally valid form that the step rejected means a wrong code.
		if ($result->form->isValid()) {
			$this->attemptLimiter->registerFailure($userIdentifier);
			if ($this->attemptLimiter->isLocked($userIdentifier)) {
				return $this->lockOut();
			}

			$result->form->addError(new FormError(self::ERROR_MESSAGE));
		}

		return $this->renderTemplate($workflow, $step, $result->form);
	}

	private function completeChallenge(AuthenticationToken $token, string $userIdentifier): Response
	{
		$this->runner->remove(self::SESSION_KEY);
		$this->attemptLimiter->reset($userIdentifier);

		$token->setIsAuthenticated(true);
		$this->tokenStorage->setToken($token);

		$target = $this->targetPathStorage->pull();
		if (null !== $target) {
			return new RedirectResponse($target);
		}

		return $this->redirectToRoute('home');
	}

	private function restartChallenge(AuthenticationToken $token): Response
	{
		$user = $token->getUser();
		if (!$user instanceof User || null === ($snapshot = $this->resolveSnapshot($user))) {
			return $this->blockInvalidConfiguration();
		}
		$userId = $this->resolveUserId($token);
		$this->runner->remove(self::SESSION_KEY);
		$workflow = $this->startWorkflow($token, $userId, $snapshot);
		$step = $this->requireFormStep($workflow);
		$this->runner->prepare(self::SESSION_KEY, $workflow, $step);
		$this->addFlash('info', 'The MFA workflow changed. Complete the refreshed challenge to continue.');

		return $this->renderTemplate($workflow, $step);
	}

	/** @param array<string,mixed> $sessionContext */
	private function resolveSnapshot(User $user, array $sessionContext = []): ?MfaWorkflowSnapshot
	{
		try {
			return $this->workflowDefinitions->resolve($user, $sessionContext);
		} catch (\InvalidArgumentException|\LogicException) {
			return null;
		}
	}

	private function blockInvalidConfiguration(): Response
	{
		$this->runner->remove(self::SESSION_KEY);
		$this->tokenStorage->setToken(null);
		$this->addFlash('error', 'Multi-factor authentication settings require attention. Please try again later or contact an administrator.');

		return $this->redirectToRoute('html_login');
	}

	private function lockOut(): Response
	{
		$this->runner->remove(self::SESSION_KEY);
		$this->tokenStorage->setToken(null);
		$this->addFlash('error', self::LOCKED_MESSAGE);

		return $this->redirectToRoute('html_login');
	}

	/**
	 * @param FormInterface<mixed>|null $form
	 */
	private function renderTemplate(
		WorkflowInterface $workflow,
		FormStepInterface $step,
		?FormInterface $form = null,
	): Response {
		return $this->render(self::TEMPLATE, [
			'doc' => ['title' => 'Multi-factor authentication'],
			'workflow' => $workflow,
			'step' => $step,
			'form' => $form ?? $this->runner->createForm($workflow, $step),
		]);
	}
}
