<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\FirewallCompiledRuleSet;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackConverter;
use iikiti\CMS\Security\Firewall\FirewallRulePackManager;
use iikiti\CMS\Security\Firewall\FirewallRulePackRejected;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceInterface;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use iikiti\CMS\Security\Firewall\FirewallRuleSetCache;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FirewallRulePackManagerTest extends TestCase
{
	public function testVerifiesSignedPackAndAtomicallyActivatesConvertedRules(): void
	{
		[$manager, $store, $directory] = $this->createManager();
		try {
			$manager->refresh('test-core');

			$rules = $store->getActiveRules();
			self::assertCount(1, $rules);
			self::assertSame('test-core:sample', $rules[0]['id']);
			self::assertSame('1.0.0', $store->getStatuses()[0]['activeVersion']);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	public function testRejectedPackLeavesPreviousVersionActive(): void
	{
		[$manager, $store, $directory] = $this->createManager();
		try {
			$manager->refresh('test-core');
			$invalidManager = $this->createManager('unsupported');
			try {
				$invalidManager[0]->refresh('test-core');
			} catch (FirewallRulePackRejected) {
				// The active pointer must remain on the previously verified copy.
			} finally {
				$this->removeDirectory($invalidManager[2]);
			}

			self::assertSame('1.0.0', $store->getStatuses()[0]['activeVersion']);
			self::assertSame('test-core:sample', $store->getActiveRules()[0]['id']);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	public function testPreviouslyVerifiedVersionCanBeReactivatedForRollback(): void
	{
		[$manager, $store, $directory] = $this->createManager();
		try {
			$manager->refresh('test-core');
			$store->activate('test-core', '2.0.0', str_repeat('a', 64), [[
				'id' => 'test-core:next',
				'name' => 'Next rule',
				'enabled' => true,
				'action' => 'report',
				'priority' => 0,
				'conditions' => ['condition' => 'request.match', 'options' => ['target' => 'path', 'operator' => 'contains', 'value' => '/next']],
			]]);

			$store->activateVersion('test-core', '1.0.0');

			self::assertSame('1.0.0', $store->getStatuses()[0]['activeVersion']);
			self::assertSame('test-core:sample', $store->getActiveRules()[0]['id']);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	public function testScheduledRefreshCannotDowngradeAnActivePack(): void
	{
		[$manager, $store, $directory] = $this->createManager();
		try {
			$store->activate('test-core', '2.0.0', str_repeat('b', 64), [[
				'id' => 'test-core:current',
				'name' => 'Current rule',
				'enabled' => true,
				'action' => 'report',
				'priority' => 0,
				'conditions' => ['condition' => 'request.match', 'options' => ['target' => 'path', 'operator' => 'contains', 'value' => '/current']],
			]]);

			try {
				$manager->refresh('test-core');
				self::fail('A signed older version was unexpectedly activated.');
			} catch (FirewallRulePackRejected) {
				self::assertSame('2.0.0', $store->getActiveVersion('test-core'));
			}
		} finally {
			$this->removeDirectory($directory);
		}
	}

	public function testPackActivationInvalidatesCachedCompiledRulesets(): void
	{
		[$manager, , $directory, $cache] = $this->createManager();
		try {
			$compileCount = 0;
			$compile = static function () use (&$compileCount): FirewallCompiledRuleSet {
				++$compileCount;

				return new FirewallCompiledRuleSet([], new FirewallConditionRegistry());
			};
			$cache->get(9, true, $compile);

			$manager->refresh('test-core');

			$cache->get(9, true, $compile);
			self::assertSame(2, $compileCount);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	/** @return array{FirewallRulePackManager,FirewallRulePackStore,string,FirewallRuleSetCache} */
	private function createManager(string $format = 'iikiti-json-v1'): array
	{
		$keyPair = sodium_crypto_sign_keypair();
		$secretKey = sodium_crypto_sign_secretkey($keyPair);
		$source = new class(base64_encode(sodium_crypto_sign_publickey($keyPair))) implements FirewallRulePackSourceInterface {
			public function __construct(private readonly string $publicKey)
			{
			}

			public function getKey(): string
			{
				return 'test-core';
			}

			public function getName(): string
			{
				return 'Test core';
			}

			public function getUrl(): string
			{
				return 'https://rules.example.test/firewall.json';
			}

			public function getPublicKey(): string
			{
				return $this->publicKey;
			}
		};
		$registry = new FirewallRulePackSourceRegistry([$source]);
		$directory = sys_get_temp_dir().'/iikiti-firewall-test-'.bin2hex(random_bytes(6));
		$validator = new FirewallRuleValidator(new FirewallConditionRegistry([new RequestMatchCondition()]));
		$ruleSetCache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$store = new FirewallRulePackStore($directory, $registry, $validator, $ruleSetCache);
		$converter = new FirewallRulePackConverter($validator);
		$payload = 'unsupported' === $format ? 'SecAction "id:1,phase:1,pass"' : json_encode([
			'schemaVersion' => 1,
			'rules' => [[
				'id' => 'sample',
				'name' => 'Sample rule',
				'action' => 'report',
				'conditions' => ['condition' => 'request.match', 'options' => ['target' => 'path', 'operator' => 'contains', 'value' => '/bad']],
			]],
		], JSON_THROW_ON_ERROR);
		$packFormat = 'unsupported' === $format ? 'modsecurity-seclang' : $format;
		$version = '1.0.0';
		$checksum = hash('sha256', $payload);
		$signature = sodium_crypto_sign_detached(
			implode("\n", ['iikiti-firewall-pack-v1', 'test-core', $version, $packFormat, $checksum])."\n".$payload,
			$secretKey,
		);
		$envelope = json_encode([
			'source' => 'test-core',
			'version' => $version,
			'format' => $packFormat,
			'sha256' => $checksum,
			'signature' => base64_encode($signature),
			'payload' => base64_encode($payload),
		], JSON_THROW_ON_ERROR);

		return [new FirewallRulePackManager(
			new MockHttpClient(new MockResponse($envelope, ['http_code' => 200])),
			$registry,
			$converter,
			$store,
		), $store, $directory, $ruleSetCache];
	}

	private function removeDirectory(string $directory): void
	{
		$entries = @scandir($directory);
		if (false === $entries) {
			return;
		}
		foreach ($entries as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$path = $directory.'/'.$entry;
			if (is_dir($path) && !is_link($path)) {
				$this->removeDirectory($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($directory);
	}
}
