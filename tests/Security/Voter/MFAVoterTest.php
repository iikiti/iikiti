<?php

namespace iikiti\CMS\Tests\Security\Voter;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use iikiti\CMS\Security\Voter\MFAVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class MFAVoterTest extends TestCase
{
	/** @var MFAVoter<string,mixed> */
	private MFAVoter $voter;

	protected function setUp(): void
	{
		$this->voter = new MFAVoter();
	}

	public function testNormalTokenIsGrantedAuthenticatedAttribute(): void
	{
		$result = $this->voter->vote(
			$this->createStub(TokenInterface::class),
			null,
			[AuthenticatedVoter::IS_AUTHENTICATED_FULLY]
		);

		$this->assertSame(Voter::ACCESS_GRANTED, $result);
	}

	public function testNormalTokenIsDeniedMfaInProgressAttribute(): void
	{
		$result = $this->voter->vote(
			$this->createStub(TokenInterface::class),
			null,
			[MFAVoter::IS_MFA_IN_PROGRESS]
		);

		$this->assertSame(Voter::ACCESS_DENIED, $result);
	}

	public function testUnauthenticatedMfaTokenIsDenied(): void
	{
		$result = $this->voter->vote(
			new AuthenticationToken(),
			null,
			[AuthenticatedVoter::IS_AUTHENTICATED_FULLY]
		);

		$this->assertSame(Voter::ACCESS_DENIED, $result);
	}

	public function testAuthenticatedMfaTokenIsGranted(): void
	{
		$token = new AuthenticationToken();
		$token->setIsAuthenticated(true);

		$result = $this->voter->vote($token, null, [AuthenticatedVoter::IS_AUTHENTICATED]);

		$this->assertSame(Voter::ACCESS_GRANTED, $result);
	}

	public function testMfaTokenIsGrantedMfaInProgressAttribute(): void
	{
		$result = $this->voter->vote(
			new AuthenticationToken(),
			null,
			[MFAVoter::IS_MFA_IN_PROGRESS]
		);

		$this->assertSame(Voter::ACCESS_GRANTED, $result);
	}

	public function testUnsupportedAttributeAbstains(): void
	{
		$result = $this->voter->vote(
			$this->createStub(TokenInterface::class),
			null,
			['SOME_UNRELATED_ATTRIBUTE']
		);

		$this->assertSame(Voter::ACCESS_ABSTAIN, $result);
	}
}
