<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Service\SecurityHeaderStore;
use PHPUnit\Framework\TestCase;

final class SecurityHeaderStoreTest extends TestCase
{
	public function testGetPolicyReturnsDefaultWhenNoneStored(): void
	{
		$store = new SecurityHeaderStore();
		$site = new Site();

		$policy = $store->getPolicy($site);

		self::assertSame(1, $policy['version']);
		self::assertSame([], $policy['headers']);
	}

	public function testSetPolicyBumpsVersion(): void
	{
		$store = new SecurityHeaderStore();
		$site = new Site();

		$version = $store->setPolicy($site, [
			['name' => 'X-Frame-Options', 'mode' => 'on', 'value' => 'DENY'],
		]);

		self::assertSame(2, $version);

		$version = $store->setPolicy($site, [
			['name' => 'X-Frame-Options', 'mode' => 'on', 'value' => 'SAMEORIGIN'],
		]);

		self::assertSame(3, $version);
	}

	public function testGetPolicyReturnsStoredPolicy(): void
	{
		$store = new SecurityHeaderStore();
		$site = new Site();

		$store->setPolicy($site, [
			['name' => 'Content-Security-Policy', 'mode' => 'on', 'value' => "default-src 'self'"],
			['name' => 'X-Frame-Options', 'mode' => 'on', 'value' => 'DENY'],
		]);

		$policy = $store->getPolicy($site);

		self::assertSame(2, $policy['version']);
		self::assertCount(2, $policy['headers']);
		self::assertSame("default-src 'self'", $policy['headers'][0]['value']);
		self::assertSame('DENY', $policy['headers'][1]['value']);
	}

	public function testGetPolicyThrowsOnMalformedStoredPolicy(): void
	{
		$store = new SecurityHeaderStore();
		$site = new Site();
		$ref = new \ReflectionClass(SecurityHeaderStore::class);
		$property = $ref->getConstant('PROPERTY');
		$site->setProperty($property, ['not_a_policy']);

		$this->expectException(\UnexpectedValueException::class);
		$store->getPolicy($site);
	}

	public function testSetPolicyNormalizesHeaders(): void
	{
		$store = new SecurityHeaderStore();
		$site = new Site();

		$store->setPolicy($site, [
			['name' => 'X-Frame-Options', 'mode' => 'on', 'value' => 'DENY'],
		]);

		$policy = $store->getPolicy($site);
		self::assertSame('X-Frame-Options', $policy['headers'][0]['name']);
		self::assertSame('on', $policy['headers'][0]['mode']);
		self::assertSame('DENY', $policy['headers'][0]['value']);
	}
}
