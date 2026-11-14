<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

/**
 * Strict validation for stored and submitted firewall protection policies.
 *
 * Unknown keys are rejected rather than ignored so that typos cannot silently
 * weaken a protection. Returns normalized, defaulted structures.
 */
final class FirewallProtectionPolicyValidator
{
	private const RATE_LIMIT_KEYS = ['id', 'name', 'enabled', 'priority', 'inheritance', 'action', 'algorithm', 'limit', 'interval', 'burst', 'keyBy', 'match'];
	private const MATCH_KEYS = ['methods', 'pathPrefix', 'pathRegex', 'routeGroup', 'authenticated'];
	private const ALGORITHMS = ['fixed_window', 'sliding_window', 'token_bucket'];
	private const KEY_BY = ['ip', 'subnet', 'user', 'token', 'ip_and_route', 'bot_identity', 'site'];
	private const RATE_ACTIONS = ['throttle', 'report'];
	private const INHERITANCES = ['default', 'required'];
	private const ROUTE_GROUPS = ['auth', 'mfa', 'forms', 'api', 'public', 'search', 'admin', 'custom'];
	private const AUTHENTICATED = ['any', 'anonymous', 'authenticated'];
	private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
	private const DOS_KEYS = [
		'required', 'enabled', 'maxHeaderCount', 'maxHeaderBytes', 'maxUriLength', 'maxQueryParams', 'maxCookieCount', 'maxCookieBytes',
		'maxBodyBytesByGroup', 'errorStormThreshold', 'errorStormWindow', 'errorStormAction',
		'expensiveRouteConcurrencyPerIp', 'expensiveRouteConcurrencyPerSite', 'concurrencyAction',
		'autoBanEnabled', 'autoBanAction', 'autoBanWindow', 'autoBanStrikeThreshold', 'autoBanDurations',
	];
	private const BOT_KEYS = [
		'required', 'enabled', 'fcrdnsEnabled', 'blockImpersonators', 'impersonationAction', 'honeypotEnabled', 'honeypotPaths',
		'honeypotAction', 'challengeDifficulty', 'challengeTtl', 'categoryPostures', 'botOverrides',
	];
	private const BOT_CATEGORIES = ['search_engine', 'ai_training', 'ai_agent', 'monitoring', 'social_preview', 'malicious_scanner', 'custom_automation'];
	private const POSTURES = ['allow', 'throttle', 'report', 'challenge', 'block'];
	private const DOS_ACTIONS = ['report', 'enforce'];
	private const MAX_RULES = 100;
	private const MAX_HONEYPOT_PATHS = 50;
	private const MAX_INTERVAL = 86400;
	private const MAX_LIMIT = 1000000;
	private const MAX_PATTERN_LENGTH = 512;

	/**
	 * @param array<string,mixed> $policy
	 *
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	public function validate(array $policy, bool $isApplication): array
	{
		$this->assertKeys($policy, ['version', 'rateLimits', 'dos', 'bots'], 'policy');

		return [
			'rateLimits' => $this->validateRateLimits($policy['rateLimits'] ?? [], $isApplication),
			'dos' => $this->validateDos($policy['dos'] ?? [], $isApplication),
			'bots' => $this->validateBots($policy['bots'] ?? [], $isApplication),
		];
	}

	/** @return list<array<string,mixed>> */
	public function validateRateLimits(mixed $rules, bool $isApplication): array
	{
		if (!is_array($rules) || !array_is_list($rules)) {
			throw new \InvalidArgumentException('rateLimits must be a list.');
		}
		if (count($rules) > self::MAX_RULES) {
			throw new \InvalidArgumentException('rateLimits contains too many rules.');
		}

		$seen = [];
		$normalized = [];
		foreach ($rules as $rule) {
			$validated = $this->validateRateLimit($rule, $isApplication);
			if (isset($seen[$validated['id']])) {
				throw new \InvalidArgumentException(sprintf('Duplicate rate limit id "%s".', $validated['id']));
			}
			$seen[$validated['id']] = true;
			$normalized[] = $validated;
		}

		return $normalized;
	}

	/** @return array<string,mixed> */
	public function validateDos(mixed $dos, bool $isApplication = false): array
	{
		$input = $this->requireArray($dos, 'dos');
		$this->assertKeys($input, self::DOS_KEYS, 'dos');
		$required = $this->extractRequired($input, $isApplication, 'dos', array_keys(CoreProtectionPolicyDefaults::dos()));
		$merged = array_replace(CoreProtectionPolicyDefaults::dos(), $input);

		$this->boolean($merged['enabled'], 'dos.enabled');
		$this->boolean($merged['autoBanEnabled'], 'dos.autoBanEnabled');
		foreach (['maxHeaderCount', 'maxHeaderBytes', 'maxUriLength', 'maxQueryParams', 'maxCookieCount', 'maxCookieBytes', 'errorStormThreshold', 'expensiveRouteConcurrencyPerIp', 'expensiveRouteConcurrencyPerSite', 'autoBanStrikeThreshold'] as $field) {
			$this->intRange($merged[$field], 1, 1000000, 'dos.'.$field);
		}
		foreach (['errorStormWindow', 'autoBanWindow'] as $field) {
			$this->intRange($merged[$field], 1, self::MAX_INTERVAL, 'dos.'.$field);
		}
		$merged['maxBodyBytesByGroup'] = $this->validateBodyLimits($merged['maxBodyBytesByGroup']);
		$merged['autoBanDurations'] = $this->validateDurations($merged['autoBanDurations']);
		foreach (['errorStormAction', 'concurrencyAction', 'autoBanAction'] as $field) {
			$this->oneOf($merged[$field], self::DOS_ACTIONS, 'dos.'.$field);
		}

		return $this->withRequired($merged, $required);
	}

	/** @return array<string,mixed> */
	public function validateBots(mixed $bots, bool $isApplication): array
	{
		$input = $this->requireArray($bots, 'bots');
		$this->assertKeys($input, self::BOT_KEYS, 'bots');
		$required = $this->extractRequired($input, $isApplication, 'bots', array_keys(CoreProtectionPolicyDefaults::bots()));
		$merged = array_replace(CoreProtectionPolicyDefaults::bots(), $input);

		foreach (['enabled', 'fcrdnsEnabled', 'blockImpersonators', 'honeypotEnabled'] as $field) {
			$this->boolean($merged[$field], 'bots.'.$field);
		}
		$this->oneOf($merged['impersonationAction'], ['block', 'report'], 'bots.impersonationAction');
		$this->oneOf($merged['honeypotAction'], ['block', 'report'], 'bots.honeypotAction');
		$this->intRange($merged['challengeDifficulty'], 1, 32, 'bots.challengeDifficulty');
		$this->intRange($merged['challengeTtl'], 60, self::MAX_INTERVAL, 'bots.challengeTtl');
		$merged['honeypotPaths'] = $this->validatePaths($merged['honeypotPaths']);
		$merged['categoryPostures'] = $this->validatePostures($merged['categoryPostures']);
		$merged['botOverrides'] = $this->validateOverrides($merged['botOverrides']);

		return $this->withRequired($merged, $required);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validateRateLimit(mixed $rule, bool $isApplication): array
	{
		$input = $this->requireArray($rule, 'rate limit');
		$this->assertKeys($input, self::RATE_LIMIT_KEYS, 'rate limit');

		$id = $this->nonEmptyString($input['id'] ?? null, 'rate limit id', 128);
		$name = $this->nonEmptyString($input['name'] ?? $id, 'rate limit name', 255);
		$inheritance = (string) ($input['inheritance'] ?? 'default');
		$this->oneOf($inheritance, self::INHERITANCES, 'rate limit inheritance');
		if (!$isApplication && 'default' !== $inheritance) {
			throw new \InvalidArgumentException('Site rate limits cannot set inheritance.');
		}
		$algorithm = (string) ($input['algorithm'] ?? 'sliding_window');
		$this->oneOf($algorithm, self::ALGORITHMS, 'rate limit algorithm');
		$keyBy = (string) ($input['keyBy'] ?? 'ip');
		$this->oneOf($keyBy, self::KEY_BY, 'rate limit keyBy');
		$action = (string) ($input['action'] ?? 'report');
		$this->oneOf($action, self::RATE_ACTIONS, 'rate limit action');
		$limit = $this->intRange($input['limit'] ?? null, 1, self::MAX_LIMIT, 'rate limit limit');
		$interval = $this->intRange($input['interval'] ?? null, 1, self::MAX_INTERVAL, 'rate limit interval');
		$burst = null;
		if ('token_bucket' === $algorithm) {
			$burst = $this->intRange($input['burst'] ?? $limit, 1, self::MAX_LIMIT, 'rate limit burst');
		} elseif (isset($input['burst'])) {
			throw new \InvalidArgumentException('rate limit burst is only valid for token_bucket.');
		}

		return [
			'id' => $id,
			'name' => $name,
			'enabled' => $this->boolean($input['enabled'] ?? true, 'rate limit enabled'),
			'priority' => $this->intRange($input['priority'] ?? 0, -1000, 1000, 'rate limit priority'),
			'inheritance' => $inheritance,
			'action' => $action,
			'algorithm' => $algorithm,
			'limit' => $limit,
			'interval' => $interval,
			'burst' => $burst,
			'keyBy' => $keyBy,
			'match' => $this->validateMatch($input['match'] ?? []),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validateMatch(mixed $match): array
	{
		$input = $this->requireArray($match, 'rate limit match');
		$this->assertKeys($input, self::MATCH_KEYS, 'rate limit match');

		$methods = $input['methods'] ?? [];
		if (!is_array($methods) || !array_is_list($methods)) {
			throw new \InvalidArgumentException('rate limit match methods must be a list.');
		}
		foreach ($methods as $method) {
			$this->oneOf(is_string($method) ? strtoupper($method) : '', self::METHODS, 'rate limit match method');
		}
		$pathRegex = $input['pathRegex'] ?? null;
		if (null !== $pathRegex) {
			$this->assertValidRegex((string) $pathRegex);
		}
		$routeGroup = (string) ($input['routeGroup'] ?? 'custom');
		$this->oneOf($routeGroup, self::ROUTE_GROUPS, 'rate limit match routeGroup');
		$authenticated = (string) ($input['authenticated'] ?? 'any');
		$this->oneOf($authenticated, self::AUTHENTICATED, 'rate limit match authenticated');

		return [
			'methods' => array_map(static fn (string $method): string => strtoupper($method), $methods),
			'pathPrefix' => $this->stringValue($input['pathPrefix'] ?? '', 'rate limit match pathPrefix', 512),
			'pathRegex' => null === $pathRegex ? null : (string) $pathRegex,
			'routeGroup' => $routeGroup,
			'authenticated' => $authenticated,
		];
	}

	/**
	 * @return array<int,int>
	 */
	private function validateBodyLimits(mixed $limits): array
	{
		$input = $this->requireArray($limits, 'dos.maxBodyBytesByGroup');
		$allowedGroups = ['auth', 'forms', 'api', 'public'];
		$this->assertKeys($input, $allowedGroups, 'dos.maxBodyBytesByGroup');
		$result = [];
		foreach ($input as $group => $bytes) {
			$result[$group] = $this->intRange($bytes, 0, 1073741824, 'dos.maxBodyBytesByGroup.'.$group);
		}

		return $result;
	}

	/**
	 * @return list<int>
	 */
	private function validateDurations(mixed $durations): array
	{
		if (!is_array($durations) || !array_is_list($durations) || [] === $durations || count($durations) > 20) {
			throw new \InvalidArgumentException('dos.autoBanDurations must be a non-empty list of seconds.');
		}
		$result = [];
		foreach ($durations as $duration) {
			$result[] = $this->intRange($duration, 1, self::MAX_INTERVAL * 30, 'dos.autoBanDurations entry');
		}

		return $result;
	}

	/**
	 * @return list<string>
	 */
	private function validatePaths(mixed $paths): array
	{
		if (!is_array($paths) || !array_is_list($paths) || count($paths) > self::MAX_HONEYPOT_PATHS) {
			throw new \InvalidArgumentException('bots.honeypotPaths must be a bounded list of paths.');
		}
		$result = [];
		foreach ($paths as $path) {
			$value = $this->nonEmptyString($path, 'bots.honeypotPaths entry', 512);
			if (!str_starts_with($value, '/')) {
				throw new \InvalidArgumentException('bots.honeypotPaths entries must start with "/".');
			}
			$result[] = $value;
		}

		return $result;
	}

	/**
	 * @return array<string,string>
	 */
	private function validatePostures(mixed $postures): array
	{
		$input = $this->requireArray($postures, 'bots.categoryPostures');
		$this->assertKeys($input, self::BOT_CATEGORIES, 'bots.categoryPostures');
		foreach ($input as $category => $posture) {
			$this->oneOf((string) $posture, self::POSTURES, 'bots.categoryPostures.'.$category);
		}

		return array_map(static fn (mixed $posture): string => (string) $posture, $input);
	}

	/**
	 * @return array<string,array{posture:string}>
	 */
	private function validateOverrides(mixed $overrides): array
	{
		$input = $this->requireArray($overrides, 'bots.botOverrides');
		if (count($input) > 500) {
			throw new \InvalidArgumentException('bots.botOverrides contains too many entries.');
		}
		$result = [];
		foreach ($input as $botId => $override) {
			$id = $this->nonEmptyString($botId, 'bots.botOverrides key', 128);
			$entry = $this->requireArray($override, 'bots.botOverrides entry');
			$this->assertKeys($entry, ['posture'], 'bots.botOverrides entry');
			$this->oneOf((string) ($entry['posture'] ?? ''), self::POSTURES, 'bots.botOverrides posture');
			$result[$id] = ['posture' => (string) $entry['posture']];
		}

		return $result;
	}

	private function assertValidRegex(string $pattern): void
	{
		if ('' === $pattern || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
			throw new \InvalidArgumentException('rate limit match pathRegex is empty or too long.');
		}
		$result = @preg_match($pattern, '');
		if (false === $result) {
			throw new \InvalidArgumentException('rate limit match pathRegex is not a valid regular expression.');
		}
	}

	/**
	 * Extracts the Application-only `required` list naming settings that Site policies cannot override.
	 *
	 * @param array<mixed> $input
	 * @param list<string> $settingNames
	 *
	 * @return list<string>
	 */
	private function extractRequired(array &$input, bool $isApplication, string $field, array $settingNames): array
	{
		if (!array_key_exists('required', $input)) {
			return [];
		}
		if (!$isApplication) {
			throw new \InvalidArgumentException(sprintf('Site %s settings cannot declare required keys.', $field));
		}
		$required = $input['required'];
		unset($input['required']);
		if (!is_array($required) || !array_is_list($required)) {
			throw new \InvalidArgumentException(sprintf('%s.required must be a list of setting names.', $field));
		}
		foreach ($required as $name) {
			$this->oneOf(is_string($name) ? $name : '', $settingNames, $field.'.required entry');
		}

		return array_values(array_unique($required));
	}

	/**
	 * Re-attaches the Application `required` marker so the resolver applies precedence per key.
	 *
	 * @param array<string,mixed> $merged
	 * @param list<string>        $required
	 *
	 * @return array<string,mixed>
	 */
	private function withRequired(array $merged, array $required): array
	{
		if ([] !== $required) {
			$merged[FirewallProtectionPolicyResolver::REQUIRED_KEYS] = $required;
		}

		return $merged;
	}

	/**
	 * @return array<mixed>
	 */
	private function requireArray(mixed $value, string $field): array
	{
		if (!is_array($value)) {
			throw new \InvalidArgumentException(sprintf('%s must be an object.', $field));
		}

		return $value;
	}

	/**
	 * @param array<mixed> $input
	 * @param list<string> $allowed
	 */
	private function assertKeys(array $input, array $allowed, string $field): void
	{
		$unknown = array_diff(array_map('strval', array_keys($input)), $allowed);
		if ([] !== $unknown) {
			throw new \InvalidArgumentException(sprintf('%s contains unknown keys: %s.', $field, implode(', ', $unknown)));
		}
	}

	private function boolean(mixed $value, string $field): bool
	{
		if (!is_bool($value)) {
			throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $field));
		}

		return $value;
	}

	private function intRange(mixed $value, int $min, int $max, string $field): int
	{
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new \InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', $field, $min, $max));
		}

		return $value;
	}

	/**
	 * @param list<string> $allowed
	 */
	private function oneOf(string $value, array $allowed, string $field): void
	{
		if (!in_array($value, $allowed, true)) {
			throw new \InvalidArgumentException(sprintf('%s has an unsupported value.', $field));
		}
	}

	private function nonEmptyString(mixed $value, string $field, int $maxLength): string
	{
		if (!is_string($value) || '' === trim($value) || strlen($value) > $maxLength) {
			throw new \InvalidArgumentException(sprintf('%s must be a non-empty string.', $field));
		}

		return trim($value);
	}

	private function stringValue(mixed $value, string $field, int $maxLength): string
	{
		if (!is_string($value) || strlen($value) > $maxLength) {
			throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
		}

		return $value;
	}
}
