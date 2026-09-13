<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Authentication\MfaAttemptLimiter;
use iikiti\CMS\Authentication\MfaPreferenceResolver;
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
		private readonly MfaPreferenceResolver $preferenceResolver,
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

		$workflow = $this->resolveWorkflow($token);
		$step = $this->requireFormStep($workflow);

		if ($request->isMethod(Request::METHOD_POST)) {
			return $this->handleSubmission($request, $workflow, $step, $token, $userIdentifier);
		}

		$this->runner->prepare(self::SESSION_KEY, $workflow, $step);

		return $this->renderTemplate($workflow, $step);
	}

	private function resolveWorkflow(AuthenticationToken $token): WorkflowInterface
	{
		$userId = $this->resolveUserId($token);
		$state = $this->runner->loadState(self::SESSION_KEY);

		if (null !== $state && $this->stateBelongsTo($state, $userId)) {
			return $this->runner->restore($state);
		}

		// A challenge left behind by a different user must not be reused.
		if (null !== $state) {
			$this->runner->remove(self::SESSION_KEY);
		}

		return $this->startWorkflow($token, $userId);
	}

	private function startWorkflow(AuthenticationToken $token, int|string $userId): WorkflowInterface
	{
		$user = $token->getUser();
		if (!$user instanceof User) {
			throw new \LogicException('Multi-factor authentication requires an identified user.');
		}

		$configuration = $this->preferenceResolver->resolve($user);
		$emails = $user->getEmails() ?? [];
		$email = $emails[0] ?? null;

		return $this->runner->create(self::SESSION_KEY, self::WORKFLOW_NAME, [
			'user_id' => $userId,
			MfaStepProvider::CONTEXT_METHODS => $configuration->getEnabledMethods(),
			MfaStepProvider::CONTEXT_EMAIL => is_string($email) ? $email : null,
			MfaStepProvider::CONTEXT_TOTP_SECRET => $configuration->getTotpSecret(),
			MfaStepProvider::CONTEXT_BACKUP_CODES => $configuration->getBackupCodeHashes(),
		]);
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
	private function stateBelongsTo(array $state, int|string $userId): bool
	{
		$context = $state['context'] ?? null;
		if (!is_array($context)) {
			return false;
		}

		$storedUserId = $context['user_id'] ?? null;
		if (!is_int($storedUserId) && !is_string($storedUserId)) {
			return false;
		}

		return (string) $storedUserId === (string) $userId;
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
	): Response {
		$result = $this->runner->submitRequest(self::SESSION_KEY, $workflow, $step, $request);

		if ($result->accepted) {
			if ($workflow->isComplete()) {
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
