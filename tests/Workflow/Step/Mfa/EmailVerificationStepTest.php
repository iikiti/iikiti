<?php

namespace iikiti\CMS\Tests\Workflow\Step\Mfa;

use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\Strategy\EmailTokenStrategy;
use iikiti\CMS\Workflow\Step\Mfa\EmailVerificationStep;
use PHPUnit\Framework\TestCase;

final class EmailVerificationStepTest extends TestCase
{
	public function testPrepareIssuesChallengeOnce(): void
	{
		$capturedCode = null;
		$mailer = $this->createMock(MfaCodeMailerInterface::class);
		$mailer->expects($this->once())->method('sendCode')->willReturnCallback(
			function (string $email, string $code) use (&$capturedCode): void {
				$capturedCode = $code;
			}
		);

		$step = new EmailVerificationStep(new EmailTokenStrategy(), $mailer, 'jane@example.com');

		$prepared = $step->prepare([]);

		$this->assertArrayHasKey(EmailVerificationStep::CHALLENGE_KEY, $prepared);
		$this->assertArrayHasKey(EmailVerificationStep::ISSUED_AT_KEY, $prepared);
		$this->assertIsString($capturedCode);

		$this->assertSame([], $step->prepare($prepared));
	}

	public function testValidateAcceptsCorrectCode(): void
	{
		$step = new EmailVerificationStep(new EmailTokenStrategy(), $this->createCapturingMailer($capturedCode), 'jane@example.com');
		$context = $step->prepare([]);

		$this->assertIsString($capturedCode);
		$this->assertTrue($step->validate($context + ['code' => $capturedCode]));
	}

	public function testValidateRejectsWrongCode(): void
	{
		$step = new EmailVerificationStep(new EmailTokenStrategy(), $this->createCapturingMailer($capturedCode), 'jane@example.com');
		$context = $step->prepare([]);

		$this->assertIsString($capturedCode);
		$wrong = '000000' === $capturedCode ? '111111' : '000000';

		$this->assertFalse($step->validate($context + ['code' => $wrong]));
	}

	public function testValidateRejectsExpiredChallenge(): void
	{
		$step = new EmailVerificationStep(new EmailTokenStrategy(), $this->createCapturingMailer($capturedCode), 'jane@example.com');
		$context = $step->prepare([]);
		$context[EmailVerificationStep::ISSUED_AT_KEY] = time() - 1000;

		$this->assertIsString($capturedCode);
		$this->assertFalse($step->validate($context + ['code' => $capturedCode]));
	}

	public function testPrepareReissuesExpiredChallenge(): void
	{
		$step = new EmailVerificationStep(new EmailTokenStrategy(), $this->createCapturingMailer($capturedCode), 'jane@example.com');
		$context = $step->prepare([]);
		$originalHash = $context[EmailVerificationStep::CHALLENGE_KEY];
		$context[EmailVerificationStep::ISSUED_AT_KEY] = time() - 1000;

		$reissued = $step->prepare($context);

		$this->assertArrayHasKey(EmailVerificationStep::CHALLENGE_KEY, $reissued);
		$this->assertNotSame($originalHash, $reissued[EmailVerificationStep::CHALLENGE_KEY]);
	}

	public function testProcessMarksEmailVerified(): void
	{
		$step = new EmailVerificationStep(new EmailTokenStrategy(), $this->createCapturingMailer($capturedCode), 'jane@example.com');

		$this->assertSame(['email_verified' => true], $step->process([], []));
	}

	/**
	 * @param string|null $capturedCode Receives the plain code passed to the mailer
	 */
	private function createCapturingMailer(?string &$capturedCode): MfaCodeMailerInterface
	{
		$mailer = $this->createStub(MfaCodeMailerInterface::class);
		$mailer->method('sendCode')->willReturnCallback(
			function (string $email, string $code) use (&$capturedCode): void {
				$capturedCode = $code;
			}
		);

		return $mailer;
	}
}
