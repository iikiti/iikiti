<?php

namespace iikiti\CMS\Tests\Authentication\Strategy;

use iikiti\CMS\Authentication\ChallengeInterface;
use iikiti\CMS\Authentication\Strategy\EmailTokenStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class EmailTokenStrategyTest extends TestCase
{
	public function testGenerateSecretIsSixDigits(): void
	{
		$this->assertMatchesRegularExpression('/^\d{6}$/', (new EmailTokenStrategy())->generateSecret());
	}

	public function testChallengeHashesSecretAndValidatesCorrectCode(): void
	{
		$strategy = new EmailTokenStrategy();
		$secret = $strategy->generateSecret();
		$challenge = $strategy->generateChallenge($secret);

		$this->assertInstanceOf(ChallengeInterface::class, $challenge);
		$this->assertNotSame($secret, $challenge->get());
		$this->assertCount(0, $strategy->validateChallenge($challenge, $secret));
	}

	public function testWrongCodeIsRejected(): void
	{
		$strategy = new EmailTokenStrategy();
		$secret = $strategy->generateSecret();
		$challenge = $strategy->generateChallenge($secret);
		$wrong = '000000' === $secret ? '111111' : '000000';

		$this->assertGreaterThan(0, count($strategy->validateChallenge($challenge, $wrong)));
	}

	public function testEmptySecretIsRejected(): void
	{
		$this->expectException(AuthenticationException::class);

		(new EmailTokenStrategy())->generateChallenge('');
	}

	public function testEmptyInputIsRejected(): void
	{
		$strategy = new EmailTokenStrategy();
		$challenge = $strategy->generateChallenge($strategy->generateSecret());

		$this->assertGreaterThan(0, count($strategy->validateChallenge($challenge, '')));
	}
}
