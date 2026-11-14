<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\Api\Internal\FirewallResolveController;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\Policy\FirewallResolvedPolicy;
use iikiti\CMS\Security\Firewall\Policy\RemoteFirewallProtectionResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises the API-side HMAC gate that consumer runtimes must pass before receiving policy.
 */
final class FirewallResolveControllerTest extends TestCase
{
	private const SECRET = 'test-kernel-secret';
	private const HOST = 'consumer.example.test';

	public function testRejectsRequestWithoutValidSignature(): void
	{
		$controller = $this->controller();
		$body = ['payload' => ['siteId' => null, 'includeSiteRules' => false, 'time' => time()], 'signature' => str_repeat('0', 64)];

		$response = $controller($this->post($body));

		self::assertSame(401, $response->getStatusCode());
	}

	public function testRejectsStaleTimestampEvenWithValidSignature(): void
	{
		$controller = $this->controller();
		$payload = ['siteId' => null, 'includeSiteRules' => false, 'time' => time() - 3600];

		$response = $controller($this->post($this->signed($payload)));

		self::assertSame(401, $response->getStatusCode());
	}

	public function testRejectsNonConsumerHostEvenWithValidSignature(): void
	{
		$controller = $this->controller();
		$payload = ['siteId' => null, 'includeSiteRules' => false, 'time' => time()];

		$response = $controller($this->post($this->signed($payload), 'attacker.example.test'));

		self::assertSame(400, $response->getStatusCode());
	}

	public function testReturnsResolvedPolicyWithNoStoreForValidSignedRequest(): void
	{
		$policy = new FirewallResolvedPolicy([], ['enabled' => true], ['enabled' => false]);
		$resolver = $this->createStub(FirewallProtectionPolicyResolverInterface::class);
		$resolver->method('resolveFor')->willReturn($policy);
		$controller = $this->controller($resolver);
		$payload = ['siteId' => null, 'includeSiteRules' => false, 'time' => time()];

		$response = $controller($this->post($this->signed($payload)));

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
		$decoded = json_decode((string) $response->getContent(), true);
		self::assertSame(['enabled' => true], $decoded['dos']);
		self::assertSame(['enabled' => false], $decoded['bots']);
	}

	/**
	 * The consumer signs with the same canonical JSON the controller verifies, so a payload signed by
	 * the remote resolver's scheme is accepted end-to-end.
	 */
	public function testSignatureSchemeMatchesControllerVerification(): void
	{
		$payload = ['siteId' => 4, 'includeSiteRules' => true, 'time' => time()];
		$signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), self::SECRET);
		$resolver = $this->createStub(FirewallProtectionPolicyResolverInterface::class);
		$resolver->method('resolveFor')->willReturn(new FirewallResolvedPolicy([], [], []));
		$controller = $this->controller($resolver);

		$response = $controller($this->post(['payload' => $payload, 'signature' => $signature]));

		self::assertNotSame(401, $response->getStatusCode(), 'A signature produced by the consumer scheme must verify.');
	}

	public function testRemoteResolverPathIsTheSingleSourceOfTruth(): void
	{
		self::assertSame('/api/internal/firewall/resolve', RemoteFirewallProtectionResolver::PATH);
	}

	/**
	 * @param array<string,mixed> $payload
	 *
	 * @return array{payload:array<string,mixed>,signature:string}
	 */
	private function signed(array $payload): array
	{
		$json = json_encode($payload, JSON_THROW_ON_ERROR);

		return ['payload' => $payload, 'signature' => hash_hmac('sha256', $json, self::SECRET)];
	}

	/** @param array<string,mixed> $body */
	private function post(array $body, string $host = self::HOST): Request
	{
		return Request::create('https://'.$host.RemoteFirewallProtectionResolver::PATH, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
	}

	private function controller(?FirewallProtectionPolicyResolverInterface $resolver = null): FirewallResolveController
	{
		$policyResolver = $resolver ?? $this->createStub(FirewallProtectionPolicyResolverInterface::class);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$ipRules = new FirewallIpRuleStore(
			$this->createStub(FirewallIpRuleRepository::class),
			new ArrayAdapter(),
			new NullLogger(),
		);

		return new FirewallResolveController($policyResolver, $entityManager, $ipRules, self::SECRET, [self::HOST]);
	}
}
