<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs a form-based workflow across multiple requests.
 *
 * The runner keeps the controller thin and reusable: it creates or restores a
 * workflow from the session, prepares the current step, builds its form, and
 * advances the workflow on submission. Any workflow whose steps implement
 * {@see FormStepInterface} can be driven by it.
 */
class MultiStepFormRunner
{
	public function __construct(
		private readonly WorkflowManager $workflowManager,
		private readonly WorkflowFactoryInterface $workflowFactory,
		private readonly WorkflowSessionStorage $sessionStorage,
		private readonly FormFactoryInterface $formFactory,
	) {
	}

	/**
	 * Start a new workflow and persist its initial state.
	 *
	 * @param array<string,mixed> $context
	 */
	public function create(string $sessionKey, string $workflowName, array $context = []): WorkflowInterface
	{
		$workflow = $this->workflowManager->buildWorkflow($workflowName, $context);
		$this->save($sessionKey, $workflow);

		return $workflow;
	}

	/**
	 * Restore a previously persisted workflow, or null when none exists.
	 */
	public function load(string $sessionKey): ?WorkflowInterface
	{
		$state = $this->loadState($sessionKey);
		if (null === $state) {
			return null;
		}

		return $this->restore($state);
	}

	/**
	 * Load the raw persisted state without rebuilding the workflow.
	 *
	 * @return array<string,mixed>|null
	 */
	public function loadState(string $sessionKey): ?array
	{
		return $this->sessionStorage->load($sessionKey);
	}

	/**
	 * Rebuild a workflow from already-loaded state.
	 *
	 * @param array<string,mixed> $state
	 */
	public function restore(array $state): WorkflowInterface
	{
		$name = $state['name'] ?? null;
		if (!is_string($name) || '' === $name) {
			throw new \InvalidArgumentException('Persisted workflow state must contain a name.');
		}

		return $this->workflowFactory->rebuild($name, $state);
	}

	public function save(string $sessionKey, WorkflowInterface $workflow): void
	{
		$this->sessionStorage->save($sessionKey, WorkflowSessionStorage::extractState($workflow));
	}

	public function remove(string $sessionKey): void
	{
		$this->sessionStorage->remove($sessionKey);
	}

	/**
	 * Let the current step set up any state it needs before being rendered.
	 */
	public function prepare(string $sessionKey, WorkflowInterface $workflow, FormStepInterface $step): void
	{
		$prepared = $step->prepare($workflow->getContext());
		if ([] === $prepared) {
			return;
		}

		$workflow->mergeContext($prepared);
		$this->save($sessionKey, $workflow);
	}

	/**
	 * @return FormInterface<mixed>
	 */
	public function createForm(WorkflowInterface $workflow, FormStepInterface $step): FormInterface
	{
		return $this->formFactory->create(
			$step->getFormType(),
			null,
			$step->getFormOptions($workflow->getContext())
		);
	}

	/**
	 * Binds the request to the current step's form and submits it when valid.
	 *
	 * Shared by every step-based controller so the submission protocol
	 * (binding, validation, data normalisation, persistence) stays identical.
	 */
	public function submitRequest(
		string $sessionKey,
		WorkflowInterface $workflow,
		FormStepInterface $step,
		Request $request,
	): FormSubmissionResult {
		$form = $this->createForm($workflow, $step);
		$form->handleRequest($request);

		if (!$form->isSubmitted() || !$form->isValid()) {
			return new FormSubmissionResult($form, false);
		}

		$data = $form->getData();
		$accepted = $this->submit($sessionKey, $workflow, is_array($data) ? $data : []);

		return new FormSubmissionResult($form, $accepted);
	}

	/**
	 * Advance the workflow with the submitted data.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return bool True when the step was accepted
	 */
	public function submit(string $sessionKey, WorkflowInterface $workflow, array $data): bool
	{
		if (false === $workflow->submitCurrentStep($data)) {
			return false;
		}

		if (false === $workflow->isComplete()) {
			$this->save($sessionKey, $workflow);
		}

		return true;
	}
}
