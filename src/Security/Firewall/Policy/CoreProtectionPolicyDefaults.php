<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

/**
 * Built-in protection defaults shipped with core. Application and Site policies override these.
 */
final class CoreProtectionPolicyDefaults
{
	/** @return list<array<string,mixed>> */
	public static function rateLimits(): array
	{
		return [
			[
				'id' => 'core:rl-auth-login',
				'name' => 'Login attempts',
				'enabled' => true,
				'priority' => 0,
				'inheritance' => 'required',
				'action' => 'throttle',
				'algorithm' => 'sliding_window',
				'limit' => 15,
				'interval' => 60,
				'keyBy' => 'ip',
				'match' => ['methods' => ['POST'], 'pathPrefix' => '/api/auth/login', 'routeGroup' => 'auth', 'authenticated' => 'any'],
			],
			[
				'id' => 'core:rl-auth-mfa',
				'name' => 'MFA attempts',
				'enabled' => true,
				'priority' => 0,
				'inheritance' => 'required',
				'action' => 'throttle',
				'algorithm' => 'sliding_window',
				'limit' => 20,
				'interval' => 60,
				'keyBy' => 'ip',
				'match' => ['methods' => ['POST'], 'pathPrefix' => '/api/auth/mfa', 'routeGroup' => 'mfa', 'authenticated' => 'any'],
			],
			[
				'id' => 'core:rl-auth-session-token',
				'name' => 'Session token exchange',
				'enabled' => true,
				'priority' => 0,
				'inheritance' => 'required',
				'action' => 'throttle',
				'algorithm' => 'sliding_window',
				'limit' => 30,
				'interval' => 60,
				'keyBy' => 'ip',
				'match' => ['methods' => ['POST'], 'pathPrefix' => '/api/auth/session-token', 'routeGroup' => 'auth', 'authenticated' => 'any'],
			],
			[
				'id' => 'core:rl-forms',
				'name' => 'Form submissions',
				'enabled' => true,
				'priority' => 0,
				'inheritance' => 'default',
				'action' => 'report',
				'algorithm' => 'sliding_window',
				'limit' => 60,
				'interval' => 60,
				'keyBy' => 'ip',
				'match' => ['methods' => ['POST', 'PUT', 'PATCH'], 'pathPrefix' => '/api/forms', 'routeGroup' => 'forms', 'authenticated' => 'any'],
			],
			[
				'id' => 'core:rl-public-api',
				'name' => 'Anonymous public API',
				'enabled' => true,
				'priority' => 0,
				'inheritance' => 'default',
				'action' => 'report',
				'algorithm' => 'token_bucket',
				'limit' => 120,
				'interval' => 60,
				'burst' => 30,
				'keyBy' => 'ip',
				'match' => ['methods' => [], 'pathPrefix' => '', 'routeGroup' => 'api', 'authenticated' => 'anonymous'],
			],
		];
	}

	/** @return array<string,mixed> */
	public static function dos(): array
	{
		return [
			'enabled' => true,
			'maxHeaderCount' => 100,
			'maxHeaderBytes' => 32768,
			'maxUriLength' => 4096,
			'maxQueryParams' => 128,
			'maxCookieCount' => 50,
			'maxCookieBytes' => 16384,
			'maxBodyBytesByGroup' => ['auth' => 65536, 'forms' => 1048576, 'api' => 8388608, 'public' => 262144],
			'errorStormThreshold' => 50,
			'errorStormWindow' => 60,
			'errorStormAction' => 'report',
			'expensiveRouteConcurrencyPerIp' => 10,
			'expensiveRouteConcurrencyPerSite' => 100,
			'concurrencyAction' => 'report',
			'autoBanEnabled' => true,
			'autoBanAction' => 'report',
			'autoBanWindow' => 300,
			'autoBanStrikeThreshold' => 5,
			'autoBanDurations' => [300, 1800, 21600, 86400],
		];
	}

	/** @return array<string,mixed> */
	public static function bots(): array
	{
		return [
			'enabled' => true,
			'fcrdnsEnabled' => false,
			'blockImpersonators' => true,
			'impersonationAction' => 'block',
			'honeypotEnabled' => false,
			'honeypotPaths' => [],
			'honeypotAction' => 'block',
			'challengeDifficulty' => 16,
			'challengeTtl' => 1800,
			'categoryPostures' => [
				'search_engine' => 'allow',
				'monitoring' => 'allow',
				'social_preview' => 'allow',
				'ai_agent' => 'allow',
				'ai_training' => 'block',
				'malicious_scanner' => 'block',
			],
			'botOverrides' => [],
		];
	}
}
