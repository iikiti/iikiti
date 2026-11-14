<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\SecurityHeaderPolicy;
use PHPUnit\Framework\TestCase;

final class SecurityHeaderPolicyTest extends TestCase
{
	public function testEffectiveModeEnvOffOverridesEverything(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame('off', $policy->effectiveMode('off', SecurityHeaderPolicy::SITE_MODE_FORCED_ON, true, true));
		self::assertSame('off', $policy->effectiveMode('off', SecurityHeaderPolicy::SITE_MODE_DEFAULT, true, true));
	}

	public function testEffectiveModeSiteForcedOff(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame('off', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_FORCED_OFF, true, true));
	}

	public function testEffectiveModeSiteForcedOn(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame('on', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_FORCED_ON, false, true));
		self::assertSame('on', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_FORCED_ON, true, false));
	}

	public function testEffectiveModeDefaultRequiresSecureAndSuccessful(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame('on', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_DEFAULT, true, true));
		self::assertSame('off', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_DEFAULT, false, true));
		self::assertSame('off', $policy->effectiveMode('default', SecurityHeaderPolicy::SITE_MODE_DEFAULT, true, false));
	}

	public function testNormalizeEnvMode(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_DEFAULT, $policy->normalizeEnvMode(''));
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_DEFAULT, $policy->normalizeEnvMode('default'));
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_DEFAULT, $policy->normalizeEnvMode('true'));
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_DEFAULT, $policy->normalizeEnvMode('1'));
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_OFF, $policy->normalizeEnvMode('off'));
		self::assertSame(SecurityHeaderPolicy::ENV_MODE_DEFAULT, $policy->normalizeEnvMode('garbage'));
	}

	public function testRenderHeadersOffReturnsEmpty(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		self::assertSame([], $policy->renderHeaders(
			[['name' => SecurityHeaderPolicy::HEADER_FRAME_OPTIONS, 'mode' => 'on', 'value' => 'DENY']],
			'off',
		));
	}

	public function testRenderHeadersSkipsOffAndUnknown(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		$headers = $policy->renderHeaders([
			['name' => SecurityHeaderPolicy::HEADER_FRAME_OPTIONS, 'mode' => 'off', 'value' => 'DENY'],
			['name' => 'X-Custom-Header', 'mode' => 'on', 'value' => 'x'],
			['name' => SecurityHeaderPolicy::HEADER_HSTS, 'mode' => 'on', 'value' => ''],
		], 'on');
		self::assertSame([], $headers);
	}

	public function testRenderHeadersSubstitutesNonceInCsp(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		$headers = $policy->renderHeaders([
			['name' => SecurityHeaderPolicy::HEADER_CSP, 'mode' => 'on', 'value' => "script-src 'self' __IIKITI_CSP_NONCE__"],
		], 'on', 'abc123');
		self::assertSame("script-src 'self' nonce-abc123", $headers[SecurityHeaderPolicy::HEADER_CSP]);
	}

	public function testRenderHeadersDoesNotSubstituteNonceOutsideCsp(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		$headers = $policy->renderHeaders([
			['name' => SecurityHeaderPolicy::HEADER_HSTS, 'mode' => 'on', 'value' => '__IIKITI_CSP_NONCE__'],
		], 'on', 'abc123');
		self::assertSame('__IIKITI_CSP_NONCE__', $headers[SecurityHeaderPolicy::HEADER_HSTS]);
	}

	public function testDefaultHeadersProd(): void
	{
		$policy = new SecurityHeaderPolicy('prod');
		$headers = $policy->renderHeaders([], 'on');
		self::assertArrayHasKey(SecurityHeaderPolicy::HEADER_CSP, $headers);
		self::assertArrayHasKey(SecurityHeaderPolicy::HEADER_HSTS, $headers);
		self::assertStringContainsString('includeSubDomains', $headers[SecurityHeaderPolicy::HEADER_HSTS]);
		self::assertStringContainsString('preload', $headers[SecurityHeaderPolicy::HEADER_HSTS]);
		self::assertSame('DENY', $headers[SecurityHeaderPolicy::HEADER_FRAME_OPTIONS]);
	}

	public function testDefaultHeadersDevRelaxed(): void
	{
		$policy = new SecurityHeaderPolicy('dev');
		$headers = $policy->renderHeaders([], 'on');
		self::assertStringContainsString("'unsafe-inline'", $headers[SecurityHeaderPolicy::HEADER_CSP]);
		self::assertSame('max-age=0', $headers[SecurityHeaderPolicy::HEADER_HSTS]);
		self::assertSame('SAMEORIGIN', $headers[SecurityHeaderPolicy::HEADER_FRAME_OPTIONS]);
	}
}
