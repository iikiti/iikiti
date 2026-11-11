<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Secrets\SecretsKeyring;
use PHPUnit\Framework\TestCase;

final class SecretsKeyringTest extends TestCase
{
	public function testWrappedValuesAuthenticateAndUseConfiguredKeyIds(): void
	{
		$keyring = new SecretsKeyring(['old' => base64_encode(str_repeat('o', 32)), 'new' => base64_encode(str_repeat('n', 32))], 'new');
		$wrapped = $keyring->wrap('site master key', 'site:42');

		self::assertSame('new', $keyring->getActiveKeyId());
		self::assertSame('site master key', $keyring->unwrap('new', $wrapped, 'site:42'));
		$this->expectException(\UnexpectedValueException::class);
		$keyring->unwrap('new', $wrapped, 'site:43');
	}

	public function testKeyringRejectsInvalidAndUnavailableKeys(): void
	{
		try {
			new SecretsKeyring(['bad' => base64_encode('short')], 'bad');
			self::fail('Short wrapping keys must be rejected.');
		} catch (\InvalidArgumentException) {
		}

		$keyring = new SecretsKeyring(['active' => base64_encode(str_repeat('a', 32))], 'active');
		$this->expectException(\UnexpectedValueException::class);
		$keyring->unwrap('missing', base64_encode(str_repeat('x', 64)), 'scope');
	}
}
