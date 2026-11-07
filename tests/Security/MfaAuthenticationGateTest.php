<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\MfaAuthenticationGate;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MfaAuthenticationGateTest extends TestCase
{
	public function testRejectsPendingMfaProxyEvenIfAuthorizationVoterIsMisconfigured(): void
	{
		$pending = new AuthenticationToken();
		$pending->setAssociatedToken(new UsernamePasswordToken($this->createStub(User::class), 'main', ['ROLE_ADMIN']));
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturn(true);
		$storage = $this->createStub(TokenStorageInterface::class);
		$storage->method('getToken')->willReturn($pending);

		self::assertFalse((new MfaAuthenticationGate($security, $storage))->allowsFullyAuthenticatedUser());
	}

	public function testAllowsFullyAuthenticatedSessionToken(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturn(true);
		$storage = $this->createStub(TokenStorageInterface::class);

		self::assertTrue((new MfaAuthenticationGate($security, $storage))->allowsFullyAuthenticatedUser());
	}

	public function testAllowsCompletedMfaProxyOnlyWhenFullyAuthenticated(): void
	{
		$completed = new AuthenticationToken();
		$completed->setAssociatedToken(new UsernamePasswordToken($this->createStub(User::class), 'main', ['ROLE_ADMIN']));
		$completed->setIsAuthenticated(true);
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturn(true);
		$storage = $this->createStub(TokenStorageInterface::class);
		$storage->method('getToken')->willReturn($completed);

		self::assertTrue((new MfaAuthenticationGate($security, $storage))->allowsFullyAuthenticatedUser());
	}

	public function testRejectsIncompleteAuthentication(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturn(false);
		$storage = $this->createStub(TokenStorageInterface::class);

		self::assertFalse((new MfaAuthenticationGate($security, $storage))->allowsFullyAuthenticatedUser());
	}
}
