<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Graph;

final class WorkflowGraphCompiler
{
	private const MAX_GRAPH_BYTES = 262144;
	private const MAX_PLACES = 100;
	private const MAX_TRANSITIONS = 200;
	private const MAX_FIELDS_PER_PLACE = 50;

	private const ALLOWED_FORM_FIELD_TYPES = ['checkbox', 'choice', 'email', 'number', 'text', 'textarea'];

	/**
	 * Security-reserved form field names: MFA proof flags and workflow context
	 * keys must never be settable through form data, or an admin-authored graph
	 * could satisfy the proof gate without a verified factor.
	 */
	private const RESERVED_FORM_FIELD_NAMES = ['user_id', 'mfa_backup_code_hashes_used', 'backup_code_used'];

	public function __construct(private readonly ?\iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry $mfaFactors = null)
	{
	}

	/** @return list<string> */
	public function getSupportedFormFieldTypes(): array
	{
		return self::ALLOWED_FORM_FIELD_TYPES;
	}

	/** @param array<string,mixed> $document */
	public function compile(array $document): WorkflowGraph
	{
		try {
			$encoded = json_encode($document, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Workflow graph is not valid JSON data.', previous: $exception);
		}
		if (strlen($encoded) > self::MAX_GRAPH_BYTES) {
			throw new \InvalidArgumentException('Workflow graph exceeds the 256 KiB size limit.');
		}
		$unknown = array_diff(array_keys($document), ['type', 'places', 'transitions', 'initialPlaces', 'finalPlaces', 'metadata']);
		if ([] !== $unknown) {
			throw new \InvalidArgumentException(sprintf('Unsupported workflow graph property "%s".', (string) reset($unknown)));
		}
		if (isset($document['type']) && 'workflow' !== $document['type']) {
			throw new \InvalidArgumentException('Runtime workflow graphs must use Symfony workflow semantics.');
		}
		$isAssetProcessor = is_array(($document['metadata'] ?? null)['assetProcessor'] ?? null);

		$rawPlaces = $document['places'] ?? null;
		if (!is_array($rawPlaces) || !array_is_list($rawPlaces) || [] === $rawPlaces || count($rawPlaces) > self::MAX_PLACES) {
			throw new \InvalidArgumentException('Workflow graph places must be a non-empty list of at most 100 places.');
		}
		$places = [];
		$placesMetadata = [];
		foreach ($rawPlaces as $rawPlace) {
			if (is_string($rawPlace)) {
				$id = $rawPlace;
				$metadata = [];
			} elseif (is_array($rawPlace)) {
				$unknown = array_diff(array_keys($rawPlace), ['id', 'metadata']);
				if ([] !== $unknown) {
					throw new \InvalidArgumentException(sprintf('Unsupported workflow place property "%s".', (string) reset($unknown)));
				}
				$id = $rawPlace['id'] ?? null;
				$metadata = $rawPlace['metadata'] ?? [];
			} else {
				throw new \InvalidArgumentException('Workflow places must be identifiers or place objects.');
			}
			if (!is_string($id) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $id)) {
				throw new \InvalidArgumentException('Workflow place IDs must be stable identifiers.');
			}
			$metadata = $this->validateMetadata($metadata, ['label', 'form', 'humanTask', 'mfaFactorKey']);
			if (isset($metadata['mfaFactorKey']) && !$this->isProviderKey($metadata['mfaFactorKey'])) {
				throw new \InvalidArgumentException(sprintf('Workflow place "%s" references an invalid MFA factor key.', $id));
			}
			if (isset($metadata['form'])) {
				$this->validateForm($metadata['form'], $this->isMfaGraph($document));
			}
			if (isset($metadata['humanTask'])) {
				$this->validateHumanTask($metadata['humanTask']);
			}
			$places[] = $id;
			if ([] !== $metadata) {
				$placesMetadata[$id] = $metadata;
			}
		}

		$rawTransitions = $document['transitions'] ?? null;
		if (!is_array($rawTransitions) || !array_is_list($rawTransitions) || count($rawTransitions) > self::MAX_TRANSITIONS) {
			throw new \InvalidArgumentException('Workflow graph transitions must be a list of at most 200 transitions.');
		}
		$transitions = [];
		foreach ($rawTransitions as $rawTransition) {
			if (!is_array($rawTransition)) {
				throw new \InvalidArgumentException('Workflow transitions must be objects.');
			}
			$unknown = array_diff(array_keys($rawTransition), ['name', 'from', 'to', 'metadata']);
			if ([] !== $unknown) {
				throw new \InvalidArgumentException(sprintf('Unsupported workflow transition property "%s".', (string) reset($unknown)));
			}
			$name = $rawTransition['name'] ?? null;
			if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $name)) {
				throw new \InvalidArgumentException('Workflow transition names must be stable identifiers.');
			}
			$from = $this->placeList($rawTransition['from'] ?? null, 'source');
			$to = $this->placeList($rawTransition['to'] ?? null, 'target');
			$metadata = $this->validateMetadata($rawTransition['metadata'] ?? [], ['label', 'automatic', 'navigation', 'assetOperation', 'incrementAttempts', 'clearLastError', 'setLastError', 'actionKey', 'guardKeys']);
			if (isset($metadata['automatic']) && !is_bool($metadata['automatic'])) {
				throw new \InvalidArgumentException(sprintf('Transition "%s" automatic metadata must be boolean.', $name));
			}
			foreach (['incrementAttempts', 'clearLastError', 'setLastError'] as $flag) {
				if (isset($metadata[$flag]) && !is_bool($metadata[$flag])) {
					throw new \InvalidArgumentException(sprintf('Transition "%s" metadata "%s" must be boolean.', $name, $flag));
				}
			}
			foreach ($from as $sourcePlace) {
				if ((isset($placesMetadata[$sourcePlace]['humanTask']) || isset($placesMetadata[$sourcePlace]['form']) || isset($placesMetadata[$sourcePlace]['mfaFactorKey'])) && (count($from) > 1 || true === ($metadata['automatic'] ?? false))) {
					throw new \InvalidArgumentException(sprintf('Transition "%s" cannot consume human, form, or MFA factor places through a join or automatic transition.', $name));
				}
			}
			if (isset($metadata['navigation']) && !in_array($metadata['navigation'], ['forward', 'back'], true)) {
				throw new \InvalidArgumentException(sprintf('Transition "%s" navigation metadata must be forward or back.', $name));
			}
			$assetOperation = $metadata['assetOperation'] ?? null;
			if ($isAssetProcessor && null === $assetOperation) {
				$assetOperation = in_array($name, ['start', 'complete', 'fail', 'skip'], true) ? $name : null;
			}
			if (null !== $assetOperation) {
				if (!$isAssetProcessor || !in_array($assetOperation, ['start', 'complete', 'fail', 'skip'], true)) {
					throw new \InvalidArgumentException(sprintf('Transition "%s" has an invalid assetOperation.', $name));
				}
				$metadata['assetOperation'] = $assetOperation;
			}
			if (isset($metadata['actionKey']) && !$this->isProviderKey($metadata['actionKey'])) {
				throw new \InvalidArgumentException(sprintf('Transition "%s" actionKey must be a registered provider key.', $name));
			}
			if (isset($metadata['guardKeys'])) {
				$this->validateProviderKeys($metadata['guardKeys'], $name);
			}
			if ('start' === $assetOperation && count($from) > 1) {
				foreach ($from as $index => $sourcePlace) {
					$transitions[] = new WorkflowGraphTransition(sprintf('asset_start_%d', $index), [$sourcePlace], $to, $metadata);
				}
			} else {
				$transitions[] = new WorkflowGraphTransition($name, $from, $to, $metadata);
			}
		}

		$initialPlaces = $this->placeList($document['initialPlaces'] ?? null, 'initial');
		$finalPlaces = $this->placeList($document['finalPlaces'] ?? null, 'final');
		$workflowMetadata = $this->validateMetadata($document['metadata'] ?? [], ['label', 'policyKey', 'mode', 'assetProcessor']);
		$workflowMetadata['mode'] ??= 'durable';
		if (!in_array($workflowMetadata['mode'], ['durable', 'session'], true)) {
			throw new \InvalidArgumentException('Workflow mode must be durable or session.');
		}
		foreach (['policyKey'] as $key) {
			if (isset($workflowMetadata[$key]) && !$this->isProviderKey($workflowMetadata[$key])) {
				throw new \InvalidArgumentException(sprintf('Workflow metadata "%s" must be a stable key.', $key));
			}
		}
		if (isset($workflowMetadata['assetProcessor'])) {
			$this->validateAssetProcessor($workflowMetadata['assetProcessor']);
		}

		$graph = new WorkflowGraph($places, $transitions, $initialPlaces, $finalPlaces, $placesMetadata, $workflowMetadata);
		if (isset($workflowMetadata['assetProcessor'])) {
			$this->validateAssetProcessorGraph($graph);
		}

		return $graph;
	}

	/** @return list<string> */
	private function placeList(mixed $places, string $label): array
	{
		if (is_string($places)) {
			$places = [$places];
		}
		if (!is_array($places) || !array_is_list($places) || [] === $places) {
			throw new \InvalidArgumentException(sprintf('Workflow %s places must be a non-empty string list.', $label));
		}
		foreach ($places as $place) {
			if (!is_string($place) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $place)) {
				throw new \InvalidArgumentException(sprintf('Invalid workflow %s place identifier.', $label));
			}
		}

		return $places;
	}

	/**
	 * MFA graphs are identified by their fixed proof-gate policy key; they get
	 * the extra reserved-field-name protection for registered proof keys.
	 *
	 * @param array<string,mixed> $document
	 */
	private function isMfaGraph(array $document): bool
	{
		$policyKey = $document['metadata']['policyKey'] ?? null;

		return 'core.mfa_proof_gate' === $policyKey;
	}

	/** @return list<string> */
	private function registeredProofKeys(): array
	{
		if (null === $this->mfaFactors) {
			return [];
		}

		$proofs = [];
		foreach ($this->mfaFactors->getKeys() as $key) {
			$provider = $this->mfaFactors->get($key);
			if (null !== $provider) {
				$proofs[] = $provider->getProofKey();
			}
		}

		return array_values(array_unique($proofs));
	}

	/**
	 * @param list<string> $proofKeys
	 */
	private function isReservedFieldName(string $name, array $proofKeys): bool
	{
		if (in_array($name, self::RESERVED_FORM_FIELD_NAMES, true) ||
			str_starts_with($name, 'mfa_') ||
			str_ends_with($name, '_verified') ||
			str_ends_with($name, '_used')) {
			return true;
		}

		return in_array($name, $proofKeys, true);
	}

	/**
	 * @param list<string> $allowedKeys
	 *
	 * @return array<string,mixed>
	 */
	private function validateMetadata(mixed $metadata, array $allowedKeys): array
	{
		if (!is_array($metadata)) {
			throw new \InvalidArgumentException('Workflow metadata must be an object.');
		}
		$unknown = array_diff(array_keys($metadata), $allowedKeys);
		if ([] !== $unknown) {
			throw new \InvalidArgumentException(sprintf('Unsupported workflow metadata key "%s".', (string) reset($unknown)));
		}
		if (isset($metadata['label']) && (!is_string($metadata['label']) || '' === trim($metadata['label']) || mb_strlen($metadata['label']) > 128)) {
			throw new \InvalidArgumentException('Workflow labels must contain 1–128 characters.');
		}

		return $metadata;
	}

	private function validateForm(mixed $form, bool $isMfaGraph = false): void
	{
		if (!is_array($form) || !is_array($form['fields'] ?? null) || !array_is_list($form['fields']) || count($form['fields']) > self::MAX_FIELDS_PER_PLACE) {
			throw new \InvalidArgumentException('Workflow form metadata requires at most 50 field definitions.');
		}
		$unknownFormKeys = array_diff(array_keys($form), ['fields', 'label', 'description']);
		if ([] !== $unknownFormKeys) {
			throw new \InvalidArgumentException(sprintf('Unsupported workflow form property "%s".', (string) reset($unknownFormKeys)));
		}
		$proofKeys = $isMfaGraph ? $this->registeredProofKeys() : [];
		foreach ($form['fields'] as $field) {
			if (!is_array($field) || !is_string($field['name'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $field['name'])) {
				throw new \InvalidArgumentException('Workflow form field names must be valid identifiers.');
			}
			if ($this->isReservedFieldName($field['name'], $proofKeys)) {
				throw new \InvalidArgumentException(sprintf('Workflow form field "%s" collides with a security-reserved workflow context key.', $field['name']));
			}
			if (!in_array($field['type'] ?? 'text', self::ALLOWED_FORM_FIELD_TYPES, true)) {
				throw new \InvalidArgumentException('Workflow form field type is not registered.');
			}
			$unknown = array_diff(array_keys($field), ['name', 'type', 'label', 'required', 'options']);
			if ([] !== $unknown) {
				throw new \InvalidArgumentException(sprintf('Unsupported workflow form field property "%s".', (string) reset($unknown)));
			}
			if (isset($field['label']) && (!is_string($field['label']) || '' === trim($field['label']) || mb_strlen($field['label']) > 128)) {
				throw new \InvalidArgumentException('Workflow form field labels must contain 1–128 characters.');
			}
			if (isset($field['required']) && !is_bool($field['required'])) {
				throw new \InvalidArgumentException('Workflow form field required must be boolean.');
			}
			$options = $field['options'] ?? [];
			if (!is_array($options)) {
				throw new \InvalidArgumentException('Workflow form field options must be an object.');
			}
			$unknownOptions = array_diff(array_keys($options), ['label', 'placeholder', 'help', 'required', 'disabled', 'choices', 'multiple', 'expanded']);
			if ([] !== $unknownOptions) {
				throw new \InvalidArgumentException(sprintf('Unsupported workflow form field option "%s".', (string) reset($unknownOptions)));
			}
		}
	}

	private function validateHumanTask(mixed $task): void
	{
		if (!is_array($task)) {
			throw new \InvalidArgumentException('Human task metadata must be an object.');
		}
		$unknown = array_diff(array_keys($task), ['assignmentKey', 'roleKey', 'userId', 'label']);
		if ([] !== $unknown) {
			throw new \InvalidArgumentException(sprintf('Unsupported human task property "%s".', (string) reset($unknown)));
		}
		$targetCount = count(array_intersect(['assignmentKey', 'roleKey', 'userId'], array_keys($task)));
		if (1 !== $targetCount) {
			throw new \InvalidArgumentException('Human task must specify exactly one assignment target.');
		}
		if (isset($task['assignmentKey']) && !$this->isProviderKey($task['assignmentKey'])) {
			throw new \InvalidArgumentException('Human task assignmentKey must be a registered resolver key.');
		}
		if (isset($task['roleKey']) && (!is_string($task['roleKey']) || !preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $task['roleKey']))) {
			throw new \InvalidArgumentException('Human task roleKey is invalid.');
		}
		if (isset($task['userId']) && !is_int($task['userId']) && !is_string($task['userId'])) {
			throw new \InvalidArgumentException('Human task userId must be an integer or string ID.');
		}
		if (isset($task['label']) && (!is_string($task['label']) || '' === trim($task['label']) || mb_strlen($task['label']) > 128)) {
			throw new \InvalidArgumentException('Human task labels must contain 1–128 characters.');
		}
	}

	private function validateAssetProcessor(mixed $definition): void
	{
		if (!is_array($definition)) {
			throw new \InvalidArgumentException('Asset processor metadata must be an object.');
		}
		$unknown = array_diff(array_keys($definition), ['processorKey', 'mimePattern', 'conditions', 'options', 'allSites']);
		if ([] !== $unknown) {
			throw new \InvalidArgumentException(sprintf('Unsupported asset processor property "%s".', (string) reset($unknown)));
		}
		if (!$this->isProviderKey($definition['processorKey'] ?? null)) {
			throw new \InvalidArgumentException('Asset processor key must be a registered stable key.');
		}
		$mimePattern = $definition['mimePattern'] ?? null;
		if (!is_string($mimePattern) || !preg_match('~^[a-z0-9.+-]+/(?:[a-z0-9.+-]+|\*)$~D', strtolower($mimePattern))) {
			throw new \InvalidArgumentException('Asset processor MIME pattern is invalid.');
		}
		if (isset($definition['allSites']) && !is_bool($definition['allSites'])) {
			throw new \InvalidArgumentException('Asset processor allSites must be boolean.');
		}
		$conditions = $definition['conditions'] ?? [];
		if (!is_array($conditions)) {
			throw new \InvalidArgumentException('Asset processor conditions must be an object.');
		}
		$this->validateJsonValue($conditions, 0);
		$options = $definition['options'] ?? [];
		if (!is_array($options)) {
			throw new \InvalidArgumentException('Asset processor options must be an object.');
		}
		$this->validateJsonValue($options, 0);
	}

	private function validateAssetProcessorGraph(WorkflowGraph $graph): void
	{
		$requiredSources = ['pending', 'running', 'failed', 'skipped'];
		$startSources = [];
		$requiredOperations = ['complete' => 'complete', 'fail' => 'failed', 'skip' => 'skipped'];
		foreach ($graph->transitions as $transition) {
			$operation = $transition->metadata['assetOperation'] ?? null;
			if ('start' === $operation && in_array('running', $transition->to, true)) {
				$startSources = [...$startSources, ...$transition->from];

				continue;
			}
			if (is_string($operation) && isset($requiredOperations[$operation]) &&
				in_array('running', $transition->from, true) &&
				in_array($requiredOperations[$operation], $transition->to, true)) {
				unset($requiredOperations[$operation]);
			}
		}
		if ([] !== $requiredOperations || [] !== array_diff($requiredSources, array_unique($startSources))) {
			throw new \InvalidArgumentException('Asset processor graph must define retryable start and running-to-complete/fail/skip operations.');
		}
		if (!in_array('complete', $graph->finalPlaces, true)) {
			throw new \InvalidArgumentException('Asset processor graph must mark complete as a final place.');
		}
	}

	private function validateJsonValue(mixed $value, int $depth): void
	{
		if ($depth > 6) {
			throw new \InvalidArgumentException('Workflow option nesting exceeds the limit.');
		}
		if (is_string($value)) {
			if (mb_strlen($value) > 4096) {
				throw new \InvalidArgumentException('Workflow option strings exceed the limit.');
			}

			return;
		}
		if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
			return;
		}
		if (!is_array($value) || count($value) > 100) {
			throw new \InvalidArgumentException('Workflow option values must be bounded JSON data.');
		}
		foreach ($value as $nested) {
			$this->validateJsonValue($nested, $depth + 1);
		}
	}

	/** @param array<array-key,mixed> $keys */
	private function validateProviderKeys(array $keys, string $transitionName): void
	{
		if (!array_is_list($keys) || count($keys) > 16) {
			throw new \InvalidArgumentException(sprintf('Transition "%s" guardKeys must be a list of at most 16 registered keys.', $transitionName));
		}
		foreach ($keys as $key) {
			if (!$this->isProviderKey($key)) {
				throw new \InvalidArgumentException(sprintf('Transition "%s" contains an invalid guard key.', $transitionName));
			}
		}
	}

	private function isProviderKey(mixed $key): bool
	{
		return is_string($key) && preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key);
	}
}
