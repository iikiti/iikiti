<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\FirewallRulePackRefreshTaskProvider;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceInterface;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceRegistry;
use PHPUnit\Framework\TestCase;

final class FirewallRulePackRefreshTaskProviderTest extends TestCase
{
	public function testCreatesGlobalRefreshMessagesForRegisteredSources(): void
	{
		$provider = new FirewallRulePackRefreshTaskProvider(new FirewallRulePackSourceRegistry([$this->createSource()]));
		$messages = iterator_to_array($provider->createMessages(['sources' => []], null));

		self::assertCount(1, $messages);
		self::assertSame('security.firewall.pack_refresh', $messages[0]->type);
		self::assertSame(['source' => 'acme-core'], $messages[0]->payload);
	}

	public function testRejectsSiteScopedRefreshSchedules(): void
	{
		$provider = new FirewallRulePackRefreshTaskProvider(new FirewallRulePackSourceRegistry([$this->createSource()]));

		$this->expectException(\InvalidArgumentException::class);
		$provider->getBatchConfiguration(['sources' => ['acme-core']], 3);
	}

	private function createSource(): FirewallRulePackSourceInterface
	{
		return new class implements FirewallRulePackSourceInterface {
			public function getKey(): string
			{
				return 'acme-core';
			}

			public function getName(): string
			{
				return 'Acme core';
			}

			public function getUrl(): string
			{
				return 'https://rules.example.test/acme.json';
			}

			public function getPublicKey(): string
			{
				return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
			}
		};
	}
}
