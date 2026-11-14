<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResponseHeaderPolicy
{
	private const RESERVED = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length', 'content-encoding', 'content-type', 'content-range', 'content-disposition', 'set-cookie', 'date', 'server', 'location', 'cache-control', 'expires', 'etag', 'last-modified', 'vary'];
	private const SENSITIVE = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token', 'x-access-token'];
	private const TOKEN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

	/**
	 * @param array<array-key,mixed> $rules
	 *
	 * @return list<array<string,mixed>>
	 */
	public function validateRules(array $rules, bool $application): array
	{
		if (!array_is_list($rules) || count($rules) > 100) {
			throw new \InvalidArgumentException('Rules must be a list of at most 100 entries.');
		}
		try {
			$encoded = json_encode($rules, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Rules must be JSON data.', 0, $exception);
		}
		if (strlen($encoded) > 65536) {
			throw new \InvalidArgumentException('Policy exceeds 65536 bytes.');
		}
		$result = [];
		foreach ($rules as $rule) {
			if (!is_array($rule) || array_diff(array_keys($rule), ['name', 'action', 'value', 'priority', 'conditions', 'inheritance'])) {
				throw new \InvalidArgumentException('Invalid rule fields.');
			}
			$name = $rule['name'] ?? null;
			if (!is_string($name) || strlen($name) > 128 || !preg_match(self::TOKEN, $name) || in_array(strtolower($name), self::RESERVED, true)) {
				throw new \InvalidArgumentException('Invalid or reserved response header name.');
			}
			$action = $rule['action'] ?? null;
			if (!in_array($action, ['set', 'remove'], true)) {
				throw new \InvalidArgumentException('Invalid header action.');
			}
			if ('set' === $action && (!isset($rule['value']) || !is_string($rule['value']) || strlen($rule['value']) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $rule['value']))) {
				throw new \InvalidArgumentException('Invalid header value.');
			}
			if ('remove' === $action && array_key_exists('value', $rule)) {
				throw new \InvalidArgumentException('Remove rules cannot have a value.');
			}
			$priority = $rule['priority'] ?? 0;
			if (!is_int($priority) || $priority < -100000 || $priority > 100000) {
				throw new \InvalidArgumentException('Invalid rule priority.');
			}
			if (!$application && array_key_exists('inheritance', $rule)) {
				throw new \InvalidArgumentException('Inheritance is application-only.');
			}
			$inheritance = $rule['inheritance'] ?? 'default';
			if (!in_array($inheritance, ['default', 'required'], true)) {
				throw new \InvalidArgumentException('Invalid inheritance.');
			}
			$normalized = ['name' => strtolower($name), 'action' => $action];
			if ('set' === $action) {
				$normalized['value'] = $rule['value'];
			}
			$normalized['priority'] = $priority;
			if ($application) {
				$normalized['inheritance'] = $inheritance;
			}
			if (array_key_exists('conditions', $rule)) {
				$nodes = 0;
				$normalized['conditions'] = $this->validateCondition($rule['conditions'], 1, $nodes);
			}
			$result[] = $normalized;
		}
		if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 65536) {
			throw new \InvalidArgumentException('Normalized policy exceeds 65536 bytes.');
		}

		return $result;
	}

	/** @return array<string,mixed> */
	private function validateCondition(mixed $condition, int $depth, int &$nodes): array
	{
		if (!is_array($condition) || ++$nodes > 64 || $depth > 8) {
			throw new \InvalidArgumentException('Invalid or oversized condition tree.');
		}
		foreach (['all', 'any', 'not'] as $group) {
			if (array_key_exists($group, $condition)) {
				if (1 !== count($condition)) {
					throw new \InvalidArgumentException('Invalid condition group.');
				}
				if ('not' === $group) {
					return [$group => $this->validateCondition($condition[$group], $depth + 1, $nodes)];
				}
				$children = $condition[$group];
				if (!is_array($children) || !array_is_list($children) || [] === $children) {
					throw new \InvalidArgumentException('Condition groups must be nonempty lists.');
				}
				$result = [];
				foreach ($children as $child) {
					$result[] = $this->validateCondition($child, $depth + 1, $nodes);
				}

				return [$group => $result];
			}
		}
		if (array_diff(array_keys($condition), ['field', 'operator', 'name', 'value'])) {
			throw new \InvalidArgumentException('Invalid condition fields.');
		}
		$field = $condition['field'] ?? null;
		$operator = $condition['operator'] ?? null;
		if (!in_array($field, ['scheme', 'method', 'path', 'status', 'header'], true) || !in_array($operator, ['present', 'equals', 'regex', 'prefix', 'class'], true) || ('present' === $operator && 'header' !== $field) || ('regex' === $operator && !in_array($field, ['path', 'header'], true)) || ('prefix' === $operator && 'path' !== $field) || ('class' === $operator && 'status' !== $field)) {
			throw new \InvalidArgumentException('Invalid condition field or operator.');
		}
		$result = ['field' => $field, 'operator' => $operator];
		if ('header' === $field) {
			$name = $condition['name'] ?? null;
			if (!is_string($name) || strlen($name) > 128 || !preg_match(self::TOKEN, $name) || in_array(strtolower($name), self::SENSITIVE, true) || preg_match('/(?:auth|cookie|token|secret|api[-_]?key)/i', $name)) {
				throw new \InvalidArgumentException('Invalid or sensitive request header.');
			}
			$result['name'] = strtolower($name);
		} elseif (array_key_exists('name', $condition)) {
			throw new \InvalidArgumentException('Name is only valid for header conditions.');
		}
		if ('present' === $operator) {
			if (array_key_exists('value', $condition)) {
				throw new \InvalidArgumentException('Presence does not take a value.');
			}

			return $result;
		}
		$value = $condition['value'] ?? null;
		if ('status' === $field ? (!is_int($value) || ('class' === $operator ? $value < 1 || $value > 5 : $value < 100 || $value > 599)) : (!is_string($value) || strlen($value) > 1024)) {
			throw new \InvalidArgumentException('Invalid condition value.');
		}
		if ('regex' === $operator) {
			if (strlen($value) > 256 || str_contains($value, '~') || str_contains($value, '(?') || str_contains($value, '(*') || preg_match('/\\\\(?:[1-9gk]|\+\d)/', $value) || false === @preg_match($this->pattern($value), '')) {
				throw new \InvalidArgumentException('Invalid or unsafe regular expression.');
			}
		}
		$result['value'] = $value;

		return $result;
	}

	private function pattern(string $pattern): string
	{
		return '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)'.$pattern.'~';
	}

	/**
	 * @param list<array<string,mixed>> $applicationRules
	 * @param list<array<string,mixed>> $siteRules
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function resolve(array $applicationRules, array $siteRules, Request $request, Response $response): array
	{
		$winners = [];
		$ranks = [];
		foreach ([[$this->validateRules($applicationRules, true), 0], [$this->validateRules($siteRules, false), 1]] as [$rules, $scope]) {
			foreach ($rules as $rule) {
				if (isset($rule['conditions']) && true !== $this->matches($rule['conditions'], $request, $response)) {
					continue;
				}
				$name = $rule['name'];
				$rank = [0 === $scope && 'required' === $rule['inheritance'] ? 2 : $scope, $rule['priority']];
				if (!isset($ranks[$name]) || $rank > $ranks[$name]) {
					$ranks[$name] = $rank;
					$winners[$name] = $rule;
				}
			}
		}

		return $winners;
	}

	/** @param array<string,mixed> $condition */
	private function matches(array $condition, Request $request, Response $response): ?bool
	{
		if (isset($condition['not'])) {
			$result = $this->matches($condition['not'], $request, $response);

			return null === $result ? null : !$result;
		}
		foreach (['all', 'any'] as $group) {
			if (isset($condition[$group])) {
				$results = [];
				foreach ($condition[$group] as $child) {
					$results[] = $this->matches($child, $request, $response);
				}
				if (in_array(null, $results, true)) {
					return null;
				}

				return 'all' === $group ? !in_array(false, $results, true) : in_array(true, $results, true);
			}
		}
		$field = $condition['field'];
		$value = match ($field) {
			'scheme' => $request->getScheme(),
			'method' => $request->getMethod(),
			'path' => $request->getPathInfo(),
			'status' => $response->getStatusCode(),
			'header' => $request->headers->get($condition['name']),
			default => null,
		};
		if ('present' === $condition['operator']) {
			return null !== $value;
		}
		if (null === $value) {
			return false;
		}
		if ('equals' === $condition['operator']) {
			return $value === $condition['value'];
		}
		if ('prefix' === $condition['operator']) {
			return str_starts_with($value, $condition['value']);
		}
		if ('class' === $condition['operator']) {
			return intdiv($value, 100) === $condition['value'];
		}
		if (strlen($value) > 8192) {
			return null;
		}
		$match = @preg_match($this->pattern($condition['value']), $value);

		return false === $match ? null : 1 === $match;
	}
}
