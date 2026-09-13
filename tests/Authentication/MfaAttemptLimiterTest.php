<?php

namespace iikiti\CMS\Tests\Authentication;

use iikiti\CMS\Authentication\MfaAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class MfaAttemptLimiterTest extends TestCase
{
	public function testNotLockedInitially(): void
	{
		$limiter = $this->createLimiter();

		$this->assertFalse($limiter->isLocked('jane'));
		$this->assertSame(0, $limiter->getSecondsUntilUnlock('jane'));
	}

	public function testLocksAfterMaxAttempts(): void
	{
		$limiter = $this->createLimiter(3);

		$limiter->registerFailure('jane');
		$limiter->registerFailure('jane');
		$this->assertFalse($limiter->isLocked('jane'));

		$limiter->registerFailure('jane');
		$this->assertTrue($limiter->isLocked('jane'));
		$this->assertGreaterThan(0, $limiter->getSecondsUntilUnlock('jane'));
	}

	public function testFailuresAreScopedPerUser(): void
	{
		$limiter = $this->createLimiter(2);

		$limiter->registerFailure('jane');
		$limiter->registerFailure('jane');

		$this->assertTrue($limiter->isLocked('jane'));
		$this->assertFalse($limiter->isLocked('john'));
	}

	public function testResetClearsFailures(): void
	{
		$limiter = $this->createLimiter(2);

		$limiter->registerFailure('jane');
		$limiter->registerFailure('jane');
		$this->assertTrue($limiter->isLocked('jane'));

		$limiter->reset('jane');

		$this->assertFalse($limiter->isLocked('jane'));
	}

	public function testExpiredLockoutStartsFreshWindow(): void
	{
		$limiter = $this->createLimiter(1, -1);

		$limiter->registerFailure('jane');

		$this->assertFalse($limiter->isLocked('jane'));
	}

	private function createLimiter(int $maxAttempts = 5, int $lockoutSeconds = 300): MfaAttemptLimiter
	{
		return new MfaAttemptLimiter(new ArrayAdapter(), $maxAttempts, $lockoutSeconds);
	}
}
