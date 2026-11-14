<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use StardotHosting\SecRuleParser\Parser;

final readonly class FirewallRulePackConverter
{
	public function __construct(private FirewallRuleValidator $validator)
	{
	}

	/** @return list<array<string,mixed>> */
	public function convert(string $format, string $payload): array
	{
		$rules = match ($format) {
			'iikiti-json-v1' => $this->convertJson($payload),
			'modsecurity-seclang' => (new SecLangRuleConverter())->convert($payload),
			default => throw new \InvalidArgumentException(sprintf('Unsupported firewall pack format "%s".', $format)),
		};

		return $this->validator->validateRules($rules);
	}

	/** @return array<mixed> */
	private function convertJson(string $payload): array
	{
		$data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($data) || [] !== array_diff(array_keys($data), ['schemaVersion', 'rules']) ||
			1 !== ($data['schemaVersion'] ?? null) || !is_array($data['rules'] ?? null)
		) {
			throw new \InvalidArgumentException('Firewall JSON packs require schemaVersion 1 and a rules list.');
		}

		return $data['rules'];
	}
}

/**
 * Strict converter for the intentionally small SecLang subset supported by the
 * in-process evaluator. This parses syntax but does not claim full CRS runtime
 * compatibility.
 */
final class SecLangRuleConverter
{
	private const VARIABLES = [
		'REQUEST_URI' => [['uri', null]],
		'REQUEST_FILENAME' => [['path', null]],
		'REQUEST_METHOD' => [['method', null]],
		'REMOTE_ADDR' => [['ip', null]],
		'REQUEST_HEADERS' => [['header', null]],
		'REQUEST_COOKIES' => [['cookie', null]],
		'ARGS' => [['query', null], ['field', null]],
		'ARGS_GET' => [['query', null]],
		'ARGS_POST' => [['field', null]],
		'REQUEST_BODY' => [['body', null], ['field', null], ['upload', null]],
		'FILES_TMP_CONTENT' => [['upload', null]],
	];

	/** @return list<array<string,mixed>> */
	public function convert(string $source): array
	{
		if (strlen($source) > 8388608) {
			throw new \InvalidArgumentException('SecLang pack exceeds the converter size limit.');
		}
		$lines = $this->joinContinuations(preg_split('/\r\n|\n|\r/', $source) ?: []);
		$rules = [];
		$pending = null;
		foreach ($lines as $lineNumber => $line) {
			if (strlen($line) > 65536) {
				throw new \InvalidArgumentException(sprintf('SecLang line %d exceeds the maximum length.', $lineNumber + 1));
			}
			$trimmed = trim($line);
			if ('' === $trimmed || str_starts_with($trimmed, '#')) {
				continue;
			}
			if (!str_starts_with($trimmed, 'SecRule ')) {
				throw new \InvalidArgumentException(sprintf('Unsupported SecLang directive on line %d.', $lineNumber + 1));
			}
			if (count($rules) + (null === $pending ? 0 : 1) >= 1000) {
				throw new \InvalidArgumentException('SecLang packs cannot exceed 1000 rules.');
			}

			$part = $this->parseRule($trimmed, $lineNumber + 1);
			if (null === $pending) {
				$pending = $part;
			} else {
				$pending['conditions'][] = $part['condition'];
				$pending['hasChain'] = $part['hasChain'];
			}

			if (!$part['hasChain']) {
				if (null === $pending['id']) {
					throw new \InvalidArgumentException(sprintf('A SecLang rule chain has no rule ID near line %d.', $lineNumber + 1));
				}
				$rules[] = [
					'id' => 'seclang:'.$pending['id'],
					'name' => $pending['message'],
					'action' => $pending['action'],
					'conditions' => 1 === count($pending['conditions']) ? $pending['conditions'][0] : ['all' => $pending['conditions']],
				];
				$pending = null;
			}
		}

		if (null !== $pending) {
			throw new \InvalidArgumentException('SecLang rule chain is incomplete.');
		}
		$this->assertParserRecognizesRuleIds($source, $rules);

		return $rules;
	}

	/** @param list<array<string,mixed>> $rules */
	private function assertParserRecognizesRuleIds(string $source, array $rules): void
	{
		$parsedIds = [];
		foreach ((new Parser())->parse($source) as $group) {
			if (!is_array($group)) {
				continue;
			}
			foreach ($group as $rule) {
				if (is_array($rule) && is_string($rule['id'] ?? null)) {
					$parsedIds[] = $rule['id'];
				}
			}
		}
		$convertedIds = array_map(static fn (array $rule): string => substr($rule['id'], strlen('seclang:')), $rules);
		sort($parsedIds);
		sort($convertedIds);
		if ([] === $convertedIds || $parsedIds !== $convertedIds) {
			throw new \InvalidArgumentException('SecLang parser could not account for every top-level rule ID.');
		}
	}

	/**
	 * @return array{condition:array<string,mixed>,conditions:list<array<string,mixed>>,id:?string,message:string,action:string,hasChain:bool}
	 */
	private function parseRule(string $line, int $lineNumber): array
	{
		$tokens = $this->tokenize($line);
		if (4 !== count($tokens) || 'SecRule' !== array_shift($tokens)) {
			throw new \InvalidArgumentException(sprintf('Malformed SecRule on line %d.', $lineNumber));
		}
		[$variables, $operator, $actions] = $tokens;
		[$operatorName, $operatorValue] = $this->parseOperator($operator);
		$actionValues = $this->parseActions($actions, $lineNumber);
		$transformations = $actionValues['transformations'];
		$condition = $this->conditionForVariables($variables, $operatorName, $operatorValue, $transformations);

		return [
			'condition' => $condition,
			'conditions' => [$condition],
			'id' => $actionValues['id'],
			'message' => $actionValues['message'],
			'action' => $actionValues['action'],
			'hasChain' => $actionValues['chain'],
		];
	}

	/** @return array{0:string,1:string} */
	private function parseOperator(string $operator): array
	{
		$operator = trim($operator);
		$operatorName = '@rx';
		if (str_starts_with($operator, '@')) {
			if (!preg_match('/^@(rx|contains|streq|beginsWith|endsWith|ipMatch)(?:\s+(.*))?$/s', $operator, $matches)) {
				throw new \InvalidArgumentException('SecLang operator is outside the supported subset.');
			}
			$operatorName = match ($matches[1]) {
				'rx' => 'regex',
				'contains' => 'contains',
				'streq' => 'equals',
				'beginsWith' => 'starts_with',
				'endsWith' => 'ends_with',
				'ipMatch' => 'ip_match',
			};
			$operator = $matches[2] ?? '';
		}

		return [$operatorName, $operator];
	}

	/** @return array{id:?string,message:string,action:string,chain:bool,transformations:list<string>} */
	private function parseActions(string $actions, int $lineNumber): array
	{
		$id = null;
		$message = sprintf('SecLang rule on line %d', $lineNumber);
		$action = 'report';
		$hasDisruptiveAction = false;
		$chain = false;
		$transformations = [];
		foreach ($this->splitActions($actions) as $value) {
			[$name, $argument] = array_pad(explode(':', $value, 2), 2, null);
			$name = strtolower(trim($name));
			$argument = null === $argument ? null : $this->unquote(trim($argument));
			switch ($name) {
				case 'id':
					if (null === $argument || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $argument)) {
						throw new \InvalidArgumentException(sprintf('Invalid SecLang rule ID on line %d.', $lineNumber));
					}
					$id = $argument;
					break;
				case 'msg':
					$message = null === $argument || '' === trim($argument) ? $message : mb_substr($argument, 0, 256);
					break;
				case 'phase':
					if (!in_array($argument, ['1', '2'], true)) {
						throw new \InvalidArgumentException('Only request-phase SecLang rules are supported.');
					}
					break;
				case 'severity':
				case 'tag':
				case 'log':
				case 'nolog':
					break;
				case 't':
					if (null === $argument) {
						throw new \InvalidArgumentException('A SecLang transformation is missing its name.');
					}
					$transformations[] = match (strtolower($argument)) {
						'none' => '__clear__',
						'lowercase' => 'lowercase',
						'urldecode' => 'urlDecode',
						'urldecodeuni' => 'urlDecodeUni',
						'htmldecode' => 'htmlEntityDecode',
						'removenulls' => 'removeNulls',
						'normalizepath' => 'normalizePath',
						default => throw new \InvalidArgumentException(sprintf('Unsupported SecLang transformation "%s".', $argument)),
					};
					if ('__clear__' === end($transformations)) {
						$transformations = [];
					}
					break;
				case 'chain':
					$chain = true;
					break;
				case 'deny':
					if ($hasDisruptiveAction) {
						throw new \InvalidArgumentException('SecLang rule has conflicting disruptive actions.');
					}
					$hasDisruptiveAction = true;
					$action = 'deny';
					break;
				case 'block':
					throw new \InvalidArgumentException('The SecLang block action requires default-action semantics and is not supported.');
				case 'pass':
					if ($hasDisruptiveAction) {
						throw new \InvalidArgumentException('SecLang rule has conflicting disruptive actions.');
					}
					$hasDisruptiveAction = true;
					$action = 'report';
					break;
				default:
					throw new \InvalidArgumentException(sprintf('Unsupported SecLang action "%s".', $name));
			}
		}

		return ['id' => $id, 'message' => $message, 'action' => $action, 'chain' => $chain, 'transformations' => $transformations];
	}

	/**
	 * @param list<string> $transformations
	 *
	 * @return array<string,mixed>
	 */
	private function conditionForVariables(string $variables, string $operator, string $value, array $transformations): array
	{
		if ('ip_match' === $operator) {
			return $this->ipMatchCondition($variables, $value);
		}

		$conditions = [];
		foreach (explode('|', $variables) as $variable) {
			$variable = trim($variable);
			if (str_starts_with($variable, '!') || str_starts_with($variable, '&')) {
				throw new \InvalidArgumentException('Negated and count-based SecLang variables are not supported.');
			}
			[$variableName, $name] = array_pad(explode(':', $variable, 2), 2, null);
			$mapping = self::VARIABLES[$variableName] ?? null;
			if (null === $mapping) {
				throw new \InvalidArgumentException(sprintf('Unsupported SecLang variable "%s".', $variableName));
			}
			if (null !== $name && !in_array($variableName, ['REQUEST_HEADERS', 'REQUEST_COOKIES'], true)) {
				throw new \InvalidArgumentException(sprintf('Named SecLang variable "%s" is not supported.', $variable));
			}
			foreach ($mapping as [$target, $defaultName]) {
				$conditions[] = [
					'condition' => 'request.match',
					'options' => [
						'target' => $target,
						'operator' => $operator,
						'value' => $value,
						'caseSensitive' => true,
						'name' => $name ?? $defaultName,
						'transformations' => $transformations,
					],
				];
			}
		}

		return 1 === count($conditions) ? $conditions[0] : ['any' => $conditions];
	}

	/**
	 * Only REMOTE_ADDR is meaningful for @ipMatch; other variables would need
	 * a different subject and are rejected rather than silently reinterpreted.
	 *
	 * @return array<string,mixed>
	 */
	private function ipMatchCondition(string $variables, string $value): array
	{
		if ('REMOTE_ADDR' !== trim($variables)) {
			throw new \InvalidArgumentException('SecLang @ipMatch is only supported against REMOTE_ADDR.');
		}

		$cidrs = array_values(array_filter(
			array_map('trim', explode(',', $value)),
			static fn (string $entry): bool => '' !== $entry,
		));
		foreach ($cidrs as $cidr) {
			IpCidrNormalizer::normalize($cidr);
		}
		if ([] === $cidrs) {
			throw new \InvalidArgumentException('SecLang @ipMatch requires at least one address or CIDR.');
		}

		return ['condition' => 'ip.cidr', 'options' => ['value' => $cidrs]];
	}

	/** @return list<string> */
	private function tokenize(string $line): array
	{
		$tokens = [];
		$token = '';
		$quote = null;
		$escaped = false;
		$length = strlen($line);
		for ($index = 0; $index < $length; ++$index) {
			$character = $line[$index];
			if ($escaped) {
				$token .= '\\'.$character;
				$escaped = false;
				continue;
			}
			if ('\\' === $character && null !== $quote) {
				$escaped = true;
				continue;
			}
			if (null !== $quote) {
				if ($character === $quote) {
					$quote = null;
				} else {
					$token .= $character;
				}
				continue;
			}
			if ('"' === $character || "'" === $character) {
				$quote = $character;
				continue;
			}
			if (ctype_space($character)) {
				if ('' !== $token) {
					$tokens[] = $token;
					$token = '';
				}
				continue;
			}
			$token .= $character;
		}
		if (null !== $quote || $escaped) {
			throw new \InvalidArgumentException('SecLang rule contains an unterminated quoted token.');
		}
		if ('' !== $token) {
			$tokens[] = $token;
		}

		return $tokens;
	}

	/** @return list<string> */
	private function splitActions(string $actions): array
	{
		$parts = [];
		$part = '';
		$quote = null;
		$escaped = false;
		for ($index = 0, $length = strlen($actions); $index < $length; ++$index) {
			$character = $actions[$index];
			if ($escaped) {
				$part .= '\\'.$character;
				$escaped = false;
				continue;
			}
			if ('\\' === $character && null !== $quote) {
				$escaped = true;
				continue;
			}
			if (null !== $quote) {
				if ($character === $quote) {
					$quote = null;
				} else {
					$part .= $character;
				}
				continue;
			}
			if ('"' === $character || "'" === $character) {
				$quote = $character;
			} elseif (',' === $character) {
				$parts[] = trim($part);
				$part = '';
			} else {
				$part .= $character;
			}
		}
		if (null !== $quote || $escaped) {
			throw new \InvalidArgumentException('SecLang action list contains an unterminated quoted value.');
		}
		$parts[] = trim($part);

		return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
	}

	private function unquote(string $value): string
	{
		if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1] && in_array($value[0], ['"', "'"], true)) {
			return substr($value, 1, -1);
		}

		return $value;
	}

	/**
	 * @param list<string> $lines
	 *
	 * @return list<string>
	 */
	private function joinContinuations(array $lines): array
	{
		$result = [];
		$pending = '';
		foreach ($lines as $line) {
			$line = rtrim($line);
			if (str_ends_with($line, '\\')) {
				$pending .= rtrim(substr($line, 0, -1)).' ';
				continue;
			}
			$result[] = $pending.$line;
			$pending = '';
		}
		if ('' !== $pending) {
			throw new \InvalidArgumentException('SecLang pack ends with an incomplete line continuation.');
		}

		return $result;
	}
}
