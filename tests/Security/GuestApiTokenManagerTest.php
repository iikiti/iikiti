<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Crypto\GuestApiTokenKeyring;
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

	public function testPreviousSigningKeyCanVerifyTokensDuringRotation(): void
	{
		$oldKey = base64_encode(str_repeat('o', 32));
		$oldManager = new GuestApiTokenManager('legacy-secret', new GuestApiTokenKeyring(['old' => $oldKey], 'old'));
		$token = $oldManager->issue('example.test');
		$rotatedManager = new GuestApiTokenManager('legacy-secret', new GuestApiTokenKeyring([
			'old' => $oldKey,
			'new' => base64_encode(str_repeat('n', 32)),
		], 'new'));

		self::assertStringStartsWith('guest2.b2xk.', $token);
		self::assertSame('example.test', $rotatedManager->decode($token)?->getSiteHost());
		self::assertStringStartsWith('guest2.bmV3.', $rotatedManager->issue('example.test'));
	}

	public function testVerifiesLegacyAppSecretTokensDuringTransition(): void
	{
		$payload = rtrim(strtr(base64_encode(json_encode([
			'host' => 'example.test',
			'exp' => time() + 60,
			'nonce' => str_repeat('a', 32),
		], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'legacy-secret', true)), '+/', '-_'), '=');
		$manager = new GuestApiTokenManager('legacy-secret', new GuestApiTokenKeyring(['new' => base64_encode(str_repeat('n', 32))], 'new'), true);

		self::assertSame('example.test', $manager->decode('guest1.'.$payload.'.'.$signature)?->getSiteHost());
		self::assertNull((new GuestApiTokenManager('legacy-secret', new GuestApiTokenKeyring(['new' => base64_encode(str_repeat('n', 32))], 'new')))->decode('guest1.'.$payload.'.'.$signature));
	}
}
