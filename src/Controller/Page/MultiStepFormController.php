<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Workflow\Definition\WorkflowInteractiveDefinitionResolver;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\MultiStepFormRunner;
use iikiti\CMS\Workflow\WorkflowInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders any form-based workflow as a multi-step form.
 *
 * This controller drives code-defined workflows and persisted admin form graphs.
 */
#[AsController]
class MultiStepFormController extends AppController
{
	private const SESSION_PREFIX = 'multistep_form_';
	private const TEMPLATE = 'multi_step_form.twig';
	private const ERROR_MESSAGE = 'Please check the form and try again.';

	public function __construct(
		Security $security,
		ContainerInterface $container,
		private readonly MultiStepFormRunner $runner,
	) {
		parent::__construct($security, $container);
	}

	#[Route(
		'/forms/{workflow}',
		name: 'multi_step_form',
		methods: ['GET', 'POST'],
		requirements: ['workflow' => '[a-z0-9_]+']
	)]
	public function run(string $workflow, Request $request): Response
	{
		$sessionKey = self::SESSION_PREFIX.$workflow;
		$instance = $this->runner->load($sessionKey) ??
			$this->runner->create($sessionKey, $workflow, $this->extractStartContext($request));

		$step = $instance->getCurrentStep();
		if (null === $step) {
			$this->runner->remove($sessionKey);

			return $this->redirectToRoute('home');
		}

		if (!$step instanceof FormStepInterface) {
			throw new \LogicException(sprintf('Workflow step "%s" does not render a form.', $step->getId()));
		}

		if ($request->isMethod(Request::METHOD_POST)) {
			return $this->handleSubmission($request, $sessionKey, $instance, $step);
		}

		$this->runner->prepare($sessionKey, $instance, $step);

		return $this->renderTemplate($instance, $step);
	}

	#[Route(
		'/forms/db/{workflow}',
		name: 'multi_step_form_db',
		methods: ['GET', 'POST'],
		requirements: ['workflow' => '[a-z][a-z0-9_.-]{1,127}']
	)]
	public function runDatabaseWorkflow(string $workflow, Request $request, WorkflowInteractiveDefinitionResolver $definitions): Response
	{
		$user = $this->getUser();
		$site = SiteRegistry::getCurrent();
		if (!$user instanceof User || null === $user->getId() || null === $site->getId()) {
			throw new NotFoundHttpException('Interactive workflow not found.');
		}

		$sessionKey = sprintf('multistep_db_%s_%s_%s', (string) $site->getId(), (string) $user->getId(), $workflow);
		$state = $this->runner->loadState($sessionKey);
		$instance = null;
		$restarted = false;
		if (null !== $state && $this->databaseStateBelongsTo($state, $workflow, $user, $site)) {
			$context = $state['context'];
			$versionId = $context[WorkflowInteractiveDefinitionResolver::SESSION_VERSION_ID] ?? null;
			$definitionId = $context[WorkflowInteractiveDefinitionResolver::SESSION_DEFINITION_ID] ?? null;
			if (is_int($versionId) || is_string($versionId)) {
				$version = $definitions->resolvePinned($versionId, $workflow, $site);
				if (null !== $version && (is_int($definitionId) || is_string($definitionId)) && (string) $version->getDefinition()->getId() === (string) $definitionId) {
					$instance = $this->runner->restore($state);
				}
			}
		}

		if (null === $instance) {
			if (null !== $state) {
				$this->runner->remove($sessionKey);
			}
			$version = $definitions->resolveCurrent($workflow, $site);
			if (null === $version) {
				throw new NotFoundHttpException('Interactive workflow not found.');
			}
			$context = $definitions->createSessionContext($version, $user, $site, $workflow);
			$instance = $this->runner->create($sessionKey, $definitions->runtimeName($workflow), $context);
			$restarted = null !== $state;
		}

		$step = $instance->getCurrentStep();
		if (null === $step) {
			$this->runner->remove($sessionKey);
			if ($instance->isComplete()) {
				return $this->render('multi_step_form_complete.twig', [
					'doc' => ['title' => 'Form submitted'],
					'workflow' => $instance,
				]);
			}
			throw new \LogicException('Interactive workflow has no active form place.');
		}
		if (!$step instanceof FormStepInterface) {
			throw new \LogicException(sprintf('Workflow step "%s" does not render a form.', $step->getId()));
		}
		if ($request->isMethod(Request::METHOD_POST) && !$restarted) {
			return $this->handleSubmission($request, $sessionKey, $instance, $step, 'multi_step_form_db', $workflow);
		}
		$this->runner->prepare($sessionKey, $instance, $step);
		if ($restarted && $request->isMethod(Request::METHOD_POST)) {
			$this->addFlash('info', 'The workflow definition changed; this form session restarted on its current version.');
		}

		return $this->renderTemplate($instance, $step, null, 'multi_step_form_db', $workflow);
	}

	private function handleSubmission(
		Request $request,
		string $sessionKey,
		WorkflowInterface $workflow,
		FormStepInterface $step,
		string $routeName = 'multi_step_form',
		?string $routeKey = null,
	): Response {
		$result = $this->runner->submitRequest($sessionKey, $workflow, $step, $request);

		if ($result->accepted) {
			if ($workflow->isComplete()) {
				$this->runner->remove($sessionKey);

				return $this->render('multi_step_form_complete.twig', [
					'doc' => ['title' => 'Form submitted'],
					'workflow' => $workflow,
				]);
			}

			return $this->redirectToRoute($routeName, ['workflow' => $routeKey ?? $workflow->getName()]);
		}

		if ($result->form->isValid()) {
			$result->form->addError(new FormError(self::ERROR_MESSAGE));
		}

		return $this->renderTemplate($workflow, $step, $result->form, $routeName, $routeKey);
	}

	/**
	 * @param FormInterface<mixed>|null $form
	 */
	private function renderTemplate(
		WorkflowInterface $workflow,
		FormStepInterface $step,
		?FormInterface $form = null,
		string $routeName = 'multi_step_form',
		?string $routeKey = null,
	): Response {
		return $this->render(self::TEMPLATE, [
			'doc' => ['title' => $step->getName()],
			'workflow' => $workflow,
			'step' => $step,
			'form' => $form ?? $this->runner->createForm($workflow, $step),
			'routeName' => $routeName,
			'routeKey' => $routeKey,
		]);
	}

	/**
	 * Passes scalar start parameters to trusted code-defined step providers.
	 * Admin-authored graphs are resolved from versioned database definitions.
	 *
	 * @return array<string,mixed>
	 */
	private function extractStartContext(Request $request): array
	{
		$context = [];
		foreach ($request->query->all() as $key => $value) {
			if ('steps' !== $key && is_scalar($value)) {
				$context[$key] = $value;
			}
		}

		return $context;
	}

	/** @param array<string,mixed> $state */
	private function databaseStateBelongsTo(array $state, string $key, User $user, Site $site): bool
	{
		$context = $state['context'] ?? null;
		if (!is_array($context)) {
			return false;
		}
		$versionId = $context[WorkflowInteractiveDefinitionResolver::SESSION_VERSION_ID] ?? null;
		$definitionId = $context[WorkflowInteractiveDefinitionResolver::SESSION_DEFINITION_ID] ?? null;
		$storedUserId = $context[WorkflowInteractiveDefinitionResolver::SESSION_USER_ID] ?? null;
		$storedSiteId = $context[WorkflowInteractiveDefinitionResolver::SESSION_SITE_ID] ?? null;
		$storedKey = $context[WorkflowInteractiveDefinitionResolver::SESSION_ROUTE_KEY] ?? null;

		return (is_int($versionId) || is_string($versionId)) &&
			(is_int($definitionId) || is_string($definitionId)) &&
			(is_int($storedUserId) || is_string($storedUserId)) &&
			(is_int($storedSiteId) || is_string($storedSiteId)) &&
			(string) $storedUserId === (string) $user->getId() &&
			(string) $storedSiteId === (string) $site->getId() &&
			$storedKey === $key;
	}
}
