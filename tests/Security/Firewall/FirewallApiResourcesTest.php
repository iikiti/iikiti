<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Security\Firewall\Bot\FirewallAutomationSecretCipher;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\State\Mapper\FirewallTrustedAutomationMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers the API-facing guarantees that must hold regardless of transport: secrets are never
 * returned, and site-scoped requests are rejected for any site other than the request-host site.
 */
final class FirewallApiResourcesTest extends TestCase
{
	private const SECRET = 'supersecret-webhook-key-value';

	protected function setUp(): void
	{
		parent::setUp();
		$this->clearCurrentSite();
	}

	protected function tearDown(): void
	{
		$this->clearCurrentSite();
		parent::tearDown();
	}

	public function testStoredHmacSecretIsMaskedAndCiphertextNeverLeaks(): void
	{
		$cipher = new FirewallAutomationSecretCipher('test-kernel-secret');
		$automation = $this->automation('hmac');
		$automation->setSecretMaterial($cipher->encryptHmacSecret(self::SECRET));

		$resource = (new FirewallTrustedAutomationMapper())->toResource($automation);

		self::assertNull($resource->secret, 'The plaintext/write-only secret must never be returned.');
		self::assertTrue($resource->hasSecret, 'Output should report that a secret is stored.');
		self::assertStringNotContainsString(self::SECRET, json_encode($resource, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString((string) $automation->getSecretMaterial(), json_encode($resource, JSON_THROW_ON_ERROR), 'Stored ciphertext must not be exposed.');
	}

	public function testAutomationWithoutSecretReportsNoSecret(): void
	{
		$resource = (new FirewallTrustedAutomationMapper())->toResource($this->automation('cidr'));

		self::assertFalse($resource->hasSecret);
		self::assertNull($resource->secret);
	}

	public function testHeaderSecretIsStoredAsHashNotPlaintext(): void
	{
		$cipher = new FirewallAutomationSecretCipher('test-kernel-secret');
		$hash = $cipher->hashHeaderSecret(self::SECRET);

		self::assertNotSame(self::SECRET, $hash);
		self::assertStringNotContainsString(self::SECRET, $hash);
		self::assertSame($hash, $cipher->hashHeaderSecret(self::SECRET), 'The header digest must be deterministic for comparison.');
	}

	public function testHmacSecretRoundTripsOnlyThroughDecryption(): void
	{
		$cipher = new FirewallAutomationSecretCipher('test-kernel-secret');
		$encrypted = $cipher->encryptHmacSecret(self::SECRET);

		self::assertStringNotContainsString(self::SECRET, $encrypted);
		self::assertSame(self::SECRET, $cipher->decryptHmacSecret($encrypted));
		self::assertNull((new FirewallAutomationSecretCipher('other-secret'))->decryptHmacSecret($encrypted), 'A different application secret must not open the material.');
	}

	public function testSiteScopeRejectsSiteIdOtherThanRequestHost(): void
	{
		SiteRegistry::pushSite($this->site(7));

		$this->expectException(NotFoundHttpException::class);
		FirewallSiteScope::assertCurrent(['siteId' => '99']);
	}

	public function testSiteScopeAcceptsMatchingRequestHostSite(): void
	{
		SiteRegistry::pushSite($this->site(7));

		self::assertSame(7, (int) FirewallSiteScope::assertCurrent(['siteId' => '7'])->getId());
	}

	public function testSiteScopeRejectsMissingSiteIdVariable(): void
	{
		SiteRegistry::pushSite($this->site(7));

		$this->expectException(NotFoundHttpException::class);
		FirewallSiteScope::assertCurrent([]);
	}

	private function automation(string $authMode): FirewallTrustedAutomation
	{
		$automation = new FirewallTrustedAutomation();
		$automation->setName('Deploy webhook');
		$automation->setHandle('deploy-webhook');
		$automation->setCategory('custom_automation');
		$automation->setAuthMode($authMode);
		$automation->setCidrs([]);
		$automation->setAllowedPaths(['/api/hooks']);

		return $automation;
	}

	private function site(int $id): Site
	{
		$site = $this->createStub(Site::class);
		$site->method('getId')->willReturn($id);

		return $site;
	}

	private function clearCurrentSite(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}
}
