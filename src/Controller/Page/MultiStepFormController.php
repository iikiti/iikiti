<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\MultiStepFormRunner;
use iikiti\CMS\Workflow\StepProvider\DynamicFormStepProvider;
use iikiti\CMS\Workflow\WorkflowInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders any form-based workflow as a multi-step form.
 *
 * This controller is the generic counterpart of the multi-factor challenge:
 * it contains no domain logic, so the same navigation and persistence can
 * later serve user-built forms.
 */
#[AsController]
class MultiStepFormController extends AppController
{
	private const SESSION_PREFIX = 'multistep_form_';
	private const TEMPLATE = 'multi_step_form.twig';
	private const ERROR_MESSAGE = 'Please check the form and try again.';
	private const MAX_STEPS_PAYLOAD = 10000;

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

	private function handleSubmission(
		Request $request,
		string $sessionKey,
		WorkflowInterface $workflow,
		FormStepInterface $step,
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

			return $this->redirectToRoute('multi_step_form', ['workflow' => $workflow->getName()]);
		}

		if ($result->form->isValid()) {
			$result->form->addError(new FormError(self::ERROR_MESSAGE));
		}

		return $this->renderTemplate($workflow, $step, $result->form);
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
			'doc' => ['title' => $step->getName()],
			'workflow' => $workflow,
			'step' => $step,
			'form' => $form ?? $this->runner->createForm($workflow, $step),
		]);
	}

	/**
	 * Builds the start context from the query string. Field-based workflows
	 * accept a JSON-encoded "steps" definition so a future builder can seed
	 * the same workflow without changing the controller.
	 *
	 * @return array<string,mixed>
	 */
	private function extractStartContext(Request $request): array
	{
		$context = [];
		foreach ($request->query->all() as $key => $value) {
			if (is_scalar($value)) {
				$context[$key] = $value;
			}
		}

		$steps = $request->query->get('steps');
		if (is_string($steps) && strlen($steps) <= self::MAX_STEPS_PAYLOAD) {
			$decoded = json_decode($steps, true);
			if (is_array($decoded)) {
				$context[DynamicFormStepProvider::CONTEXT_KEY] = $decoded;
			}
		}

		return $context;
	}
}
