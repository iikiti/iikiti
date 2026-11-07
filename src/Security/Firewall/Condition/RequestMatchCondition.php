<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Condition;

use iikiti\CMS\Security\Firewall\FirewallConditionIndexInterface;
use iikiti\CMS\Security\Firewall\FirewallConditionInterface;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;

final class RequestMatchCondition implements FirewallConditionInterface, FirewallConditionIndexInterface
{
	private const TARGETS = ['method', 'path', 'uri', 'ip', 'header', 'cookie', 'query', 'field', 'body', 'upload', 'upload_name', 'upload_type'];
	private const OPERATORS = ['equals', 'contains', 'starts_with', 'ends_with', 'regex'];
	private const TRANSFORMATIONS = ['lowercase', 'urlDecode', 'urlDecodeUni', 'htmlEntityDecode', 'removeNulls', 'normalizePath'];

	public function getName(): string
	{
		return 'request.match';
	}

	public function validateOptions(array $options): void
	{
		if ([] !== array_diff(array_keys($options), ['target', 'operator', 'value', 'name', 'caseSensitive', 'transformations'])) {
			throw new \InvalidArgumentException('Firewall request.match contains unsupported options.');
		}
		$target = $options['target'] ?? null;
		$operator = $options['operator'] ?? null;
		$value = $options['value'] ?? null;
		$name = $options['name'] ?? null;
		$caseSensitive = $options['caseSensitive'] ?? false;
		$transformations = $options['transformations'] ?? [];
		if (!is_string($target) || !in_array($target, self::TARGETS, true) ||
			!is_string($operator) || !in_array($operator, self::OPERATORS, true) ||
			!is_string($value) || '' === $value || strlen($value) > 4096 || !is_bool($caseSensitive) ||
			!is_array($transformations) || !array_is_list($transformations) || count($transformations) > 8 ||
			(null !== $name && (!is_string($name) || '' === $name || strlen($name) > 128))
		) {
			throw new \InvalidArgumentException('Firewall request.match options are invalid.');
		}
		foreach ($transformations as $transformation) {
			if (!is_string($transformation) || !in_array($transformation, self::TRANSFORMATIONS, true)) {
				throw new \InvalidArgumentException('Firewall request.match contains an unsupported transformation.');
			}
		}
		if (null !== $name && !in_array($target, ['header', 'cookie', 'query', 'field', 'upload', 'upload_name', 'upload_type'], true)) {
			throw new \InvalidArgumentException('A firewall match name is only valid for named request targets.');
		}
		if ('regex' === $operator && (str_contains($value, "\x01") || false === @preg_match(self::compilePattern($value, $caseSensitive), ''))) {
			throw new \InvalidArgumentException('Firewall regular expression is invalid.');
		}
	}

	public function getRequestIndexKeys(array $options): array
	{
		$target = $options['target'];
		$name = $options['name'] ?? null;

		return [FirewallRequestContext::indexKey($target, $name)];
	}

	public function couldMatchRequest(array $options, FirewallRequestContext $context): bool
	{
		$target = $options['target'];
		$name = $options['name'] ?? null;
		if (!$context->hasValues($target, $name)) {
			return false;
		}
		if ('equals' === $options['operator'] && [] === ($options['transformations'] ?? [])) {
			return $context->matchesExactValue(
				$target,
				$name,
				$options['value'],
				$options['caseSensitive'] ?? false,
			);
		}

		return true;
	}

	/** @param array<string,mixed> $options */
	public function matches(array $options, FirewallRequestContext $context): bool
	{
		$target = (string) $options['target'];
		$operator = (string) $options['operator'];
		$needle = (string) $options['value'];
		$name = isset($options['name']) ? (string) $options['name'] : null;
		$caseSensitive = (bool) ($options['caseSensitive'] ?? false);
		$transformations = $options['transformations'] ?? [];
		if ('equals' === $operator && [] === $transformations) {
			return $context->matchesExactValue($target, $name, $needle, $caseSensitive);
		}

		$values = match ($target) {
			'method' => [$context->method],
			'path' => [$context->path],
			'ip' => [$context->clientIp],
			default => $context->getValues($target, $name),
		};

		foreach ($values as $value) {
			$context->assertWithinBudget();
			$value = $this->transform($value, $transformations);
			$matched = match ($operator) {
				'equals' => $caseSensitive ? $value === $needle : 0 === strcasecmp($value, $needle),
				'contains' => $caseSensitive ? str_contains($value, $needle) : false !== stripos($value, $needle),
				'starts_with' => $caseSensitive ? str_starts_with($value, $needle) : str_starts_with(strtolower($value), strtolower($needle)),
				'ends_with' => $caseSensitive ? str_ends_with($value, $needle) : str_ends_with(strtolower($value), strtolower($needle)),
				'regex' => $this->matchesRegex($needle, $value, $caseSensitive),
				default => throw new \LogicException('Firewall request.match operator was not validated.'),
			};
			if ($matched) {
				return true;
			}
		}

		return false;
	}

	private static function compilePattern(string $pattern, bool $caseSensitive): string
	{
		return "\x01(*LIMIT_MATCH=50000)(*LIMIT_DEPTH=1000)(?:".$pattern.")\x01s".($caseSensitive ? '' : 'i');
	}

	private function matchesRegex(string $pattern, string $value, bool $caseSensitive): bool
	{
		$result = @preg_match(self::compilePattern($pattern, $caseSensitive), $value);
		if (false === $result) {
			throw new \iikiti\CMS\Security\Firewall\FirewallInspectionException('A firewall regular expression exceeded its execution limits.');
		}

		return 1 === $result;
	}

	/** @param list<string> $transformations */
	private function transform(string $value, array $transformations): string
	{
		foreach ($transformations as $transformation) {
			$value = match ($transformation) {
				'lowercase' => strtolower($value),
				'urlDecode' => rawurldecode($value),
				'urlDecodeUni' => $this->urlDecodeUni($value),
				'htmlEntityDecode' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'removeNulls' => str_replace("\0", '', $value),
				'normalizePath' => $this->normalizePath($value),
				default => throw new \LogicException('Firewall request.match transformation was not validated.'),
			};
		}

		return $value;
	}

	private function urlDecodeUni(string $value): string
	{
		$value = preg_replace_callback('/%u([0-9a-f]{4})/i', static function (array $match): string {
			$decoded = html_entity_decode('&#x'.$match[1].';', ENT_QUOTES | ENT_HTML5, 'UTF-8');

			return "\xef\xbf\xbd" === $decoded ? $match[0] : $decoded;
		}, $value) ?? $value;

		return rawurldecode($value);
	}

	private function normalizePath(string $value): string
	{
		$parts = [];
		foreach (explode('/', str_replace('\\', '/', $value)) as $part) {
			if ('' === $part || '.' === $part) {
				continue;
			}
			if ('..' === $part) {
				array_pop($parts);
				continue;
			}
			$parts[] = $part;
		}

		return '/'.implode('/', $parts);
	}
}
