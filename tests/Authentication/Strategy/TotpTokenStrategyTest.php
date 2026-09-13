<?php

namespace iikiti\CMS\Tests\Authentication\Strategy;

use iikiti\CMS\Authentication\Strategy\TotpTokenStrategy;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class TotpTokenStrategyTest extends TestCase
{
	public function testGeneratedCodeValidates(): void
	{
		$strategy = new TotpTokenStrategy();
		$secret = $strategy->generateSecret();
		$challenge = $strategy->generateChallenge($secret);
		$code = TOTP::createFromSecret($secret)->now();

		$this->assertNotSame('', $secret);
		$this->assertCount(0, $strategy->validateChallenge($challenge, $code));
	}

	public function testWrongCodeIsRejected(): void
	{
		$strategy = new TotpTokenStrategy();
		$secret = $strategy->generateSecret();
		$challenge = $strategy->generateChallenge($secret);
		$code = TOTP::createFromSecret($secret)->now();
		$wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

		$this->assertGreaterThan(0, count($strategy->validateChallenge($challenge, $wrong)));
	}

	public function testEmptySecretIsRejected(): void
	{
		$this->expectException(AuthenticationException::class);

		(new TotpTokenStrategy())->generateChallenge('');
	}
}
