<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Security\GuestApiTokenManager;
use PHPUnit\Framework\TestCase;

final class GuestApiTokenManagerTest extends TestCase
{
	public function testIssuesSiteBoundGuestCredential(): void
	{
		$tokens = new GuestApiTokenManager('test-secret');
		$token = $tokens->issue('WWW.Example.test');
		$guest = $tokens->decode($token);

		self::assertNotNull($guest);
		self::assertSame('www.example.test', $guest->getSiteHost());
		self::assertSame(['ROLE_NON_MEMBER'], $guest->getRoles());
		self::assertStringStartsWith('guest:', $guest->getUserIdentifier());
	}

	public function testRejectsTamperedOrWrongSecretCredentials(): void
	{
		$tokens = new GuestApiTokenManager('test-secret');
		$token = $tokens->issue('www.example.test');
		$wrongSecret = new GuestApiTokenManager('different-secret');

		self::assertNull($tokens->decode($token.'x'));
		self::assertNull($wrongSecret->decode($token));
	}

	public function testRejectsInvalidSiteHosts(): void
	{
		$tokens = new GuestApiTokenManager('test-secret');
		$this->expectException(\InvalidArgumentException::class);

		$tokens->issue('example.test/other-route');
	}
}
