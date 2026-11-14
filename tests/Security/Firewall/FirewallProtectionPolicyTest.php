<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Policy\CoreProtectionPolicyDefaults;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolver;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyValidator;
use PHPUnit\Framework\TestCase;

final class FirewallProtectionPolicyTest extends TestCase
{
	private FirewallProtectionPolicyValidator $validator;
	private FirewallProtectionPolicyResolver $resolver;

	protected function setUp(): void
	{
		$this->validator = new FirewallProtectionPolicyValidator();
		$this->resolver = new FirewallProtectionPolicyResolver();
	}

	public function testCoreDefaultsAreValidAsApplicationPolicy(): void
	{
		$validated = $this->validator->validate([
			'version' => 1,
			'rateLimits' => CoreProtectionPolicyDefaults::rateLimits(),
			'dos' => CoreProtectionPolicyDefaults::dos(),
			'bots' => CoreProtectionPolicyDefaults::bots(),
		], true);

		self::assertCount(5, $validated['rateLimits']);
		self::assertSame(120, $validated['rateLimits'][4]['limit']);
	}

	public function testRejectsUnknownTopLevelAndNestedKeys(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validate(['version' => 1, 'rateLimits' => [], 'dos' => ['bogus' => true], 'bots' => []], true);
	}

	public function testRejectsInvalidPathRegex(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('x', ['pathRegex' => '/unclosed'])], false);
	}

	public function testRejectsOutOfRangeIntervalAndLimit(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('x', ['interval' => 0])], false);
	}

	public function testRejectsZeroLimit(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('x', ['limit' => 0])], false);
	}

	public function testRejectsInheritanceOnSitePolicies(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('x', ['inheritance' => 'required'])], false);
	}

	public function testRejectsBurstOnFixedWindow(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('x', ['algorithm' => 'fixed_window', 'burst' => 5])], true);
	}

	public function testRejectsDuplicateRuleIds(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateRateLimits([$this->rule('dup'), $this->rule('dup')], true);
	}

	public function testRejectsRequiredListOnSitePolicy(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateDos(['required' => ['maxUriLength']], false);
	}

	public function testRejectsUnknownBotCategory(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->validator->validateBots(['categoryPostures' => ['nope' => 'allow']], true);
	}

	public function testSiteRuleCannotDisableApplicationRequiredRule(): void
	{
		$app = $this->applicationPolicy(['rateLimits' => [$this->rule('core:rl-auth-login', ['inheritance' => 'required', 'limit' => 15])]]);
		$site = $this->sitePolicy(['rateLimits' => [$this->rule('core:rl-auth-login', ['enabled' => false, 'limit' => 999])]]);

		$resolved = $this->resolver->resolve($app, $site);

		$login = $this->findRule($resolved['rateLimits'], 'core:rl-auth-login');
		self::assertSame(15, $login['limit']);
		self::assertTrue($login['enabled']);
	}

	public function testSiteCanOverrideApplicationDefaultRuleById(): void
	{
		$site = $this->sitePolicy(['rateLimits' => [$this->rule('core:rl-public-api', ['limit' => 5, 'action' => 'throttle'])]]);

		$resolved = $this->resolver->resolve(null, $site);

		$public = $this->findRule($resolved['rateLimits'], 'core:rl-public-api');
		self::assertSame(5, $public['limit']);
		self::assertSame('throttle', $public['action']);
	}

	public function testSiteAddsOwnRuleAlongsideDefaults(): void
	{
		$site = $this->sitePolicy(['rateLimits' => [$this->rule('site:custom', ['keyBy' => 'user', 'match' => ['routeGroup' => 'custom']])]]);

		$resolved = $this->resolver->resolve(null, $site);
		$ids = array_column($resolved['rateLimits'], 'id');

		self::assertContains('site:custom', $ids);
		self::assertContains('core:rl-auth-login', $ids);
	}

	public function testDosApplicationRequiredBeatsSiteAndDefault(): void
	{
		$app = $this->applicationPolicy(['dos' => ['maxUriLength' => 1000, 'required' => ['maxUriLength']]]);
		$site = $this->sitePolicy(['dos' => ['maxUriLength' => 500, 'maxQueryParams' => 10]]);

		$resolved = $this->resolver->resolve($app, $site);

		self::assertSame(1000, $resolved['dos']['maxUriLength']);
		self::assertSame(10, $resolved['dos']['maxQueryParams']);
	}

	public function testDosSiteBeatsApplicationDefault(): void
	{
		$app = $this->applicationPolicy(['dos' => ['maxUriLength' => 1000]]);
		$site = $this->sitePolicy(['dos' => ['maxUriLength' => 500]]);

		self::assertSame(500, $this->resolver->resolve($app, $site)['dos']['maxUriLength']);
	}

	public function testBotsApplicationRequiredPostureBeatsSite(): void
	{
		$app = $this->applicationPolicy(['bots' => [
			'categoryPostures' => ['ai_training' => 'block'],
			'required' => ['categoryPostures'],
		]]);
		$site = $this->sitePolicy(['bots' => ['categoryPostures' => ['ai_training' => 'allow']]]);

		$postures = $this->resolver->resolve($app, $site)['bots']['categoryPostures'];

		self::assertSame('block', $postures['ai_training']);
	}

	public function testCoreDefaultsApplyWithoutAnyPolicy(): void
	{
		$resolved = $this->resolver->resolve(null, null);

		self::assertSame(100, $resolved['dos']['maxHeaderCount']);
		self::assertSame('block', $resolved['bots']['categoryPostures']['ai_training']);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function rule(string $id, array $overrides = []): array
	{
		return array_replace([
			'id' => $id,
			'name' => $id,
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'default',
			'action' => 'throttle',
			'algorithm' => 'sliding_window',
			'limit' => 10,
			'interval' => 60,
			'keyBy' => 'ip',
			'match' => ['methods' => ['POST'], 'pathPrefix' => '/x', 'pathRegex' => null, 'routeGroup' => 'custom', 'authenticated' => 'any'],
		], $overrides);
	}

	/**
	 * @param array<string,mixed> $policy
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	private function applicationPolicy(array $policy): array
	{
		return $this->validator->validate($this->withDefaults($policy), true);
	}

	/**
	 * @param array<string,mixed> $policy
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	private function sitePolicy(array $policy): array
	{
		return $this->validator->validate($this->withDefaults($policy), false);
	}

	/**
	 * @param array<string,mixed> $policy
	 * @return array<string,mixed>
	 */
	private function withDefaults(array $policy): array
	{
		return array_replace(['version' => 1, 'rateLimits' => [], 'dos' => [], 'bots' => []], $policy);
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,mixed>
	 */
	private function findRule(array $rules, string $id): array
	{
		foreach ($rules as $rule) {
			if ($rule['id'] === $id) {
				return $rule;
			}
		}
		self::fail('Rule not found: '.$id);
	}
}
