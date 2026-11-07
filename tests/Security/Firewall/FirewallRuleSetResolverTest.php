<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\Firewall\CoreFirewallRules;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceInterface;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use iikiti\CMS\Security\Firewall\FirewallRuleSetCache;
use iikiti\CMS\Security\Firewall\FirewallRuleSetResolver;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FirewallRuleSetResolverTest extends TestCase
{
	public function testGlobalRulesIncludeCoreAndActivePacksButExcludeSitePolicy(): void
	{
		$directory = sys_get_temp_dir().'/iikiti-global-firewall-'.bin2hex(random_bytes(6));
		$conditionRegistry = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$validator = new FirewallRuleValidator($conditionRegistry);
		$cache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$keyPair = sodium_crypto_sign_keypair();
		$source = new class (base64_encode(sodium_crypto_sign_publickey($keyPair))) implements FirewallRulePackSourceInterface {
			public function __construct(private readonly string $publicKey)
			{
			}

			public function getKey(): string
			{
				return 'test-pack';
			}

			public function getName(): string
			{
				return 'Test pack';
			}

			public function getUrl(): string
			{
				return 'https://example.test/firewall.json';
			}

			public function getPublicKey(): string
			{
				return $this->publicKey;
			}
		};
		$packStore = new FirewallRulePackStore(
			$directory,
			new FirewallRulePackSourceRegistry([$source]),
			$validator,
			$cache,
		);
		$packRule = $this->rule('pack:deny', 'deny');
		$packStore->activate('test-pack', '1.0.0', str_repeat('a', 64), [$packRule]);
		$sitePolicies = new FirewallSitePolicyStore();
		$site = new Site();
		(new \ReflectionProperty(Site::class, 'id'))->setValue($site, 9);
		$site->setProperties(new ArrayCollection());
		$sitePolicies->setRules($site, [$this->rule('site-only:deny', 'deny')]);
		$resolver = new FirewallRuleSetResolver(
			new CoreFirewallRules(),
			$packStore,
			$sitePolicies,
			$validator,
			$conditionRegistry,
			$cache,
		);

		try {
			$globalRuleIds = array_column($resolver->getGlobalRules()->getRules(), 'id');
			$siteRuleIds = array_column($resolver->getRulesForSite($site)->getRules(), 'id');

			self::assertContains('core:owasp-sqli', $globalRuleIds);
			self::assertContains('pack:deny', $globalRuleIds);
			self::assertNotContains('site-only:deny', $globalRuleIds);
			self::assertContains('site.9:site-only:deny', $siteRuleIds);
		} finally {
			$this->removeDirectory($directory);
		}
	}

	/** @return array<string,mixed> */
	private function rule(string $id, string $action): array
	{
		return [
			'id' => $id,
			'name' => $id,
			'action' => $action,
			'conditions' => ['condition' => 'request.match', 'options' => [
				'target' => 'path', 'operator' => 'contains', 'value' => '/api',
			]],
		];
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
