<?php

namespace iikiti\CMS\Tests\Authentication\Token;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuthenticationTokenTest extends TestCase
{
	public function testProxyDelegatesToAssociatedToken(): void
	{
		$user = new InMemoryUser('jane', 'secret', ['ROLE_USER']);
		$token = $this->createProxy($user, false);

		$this->assertSame($user, $token->getUser());
		$this->assertSame('jane', $token->getUserIdentifier());
		$this->assertFalse($token->isAuthenticated());
	}

	public function testRolesAreOnlyExposedOnceAuthenticated(): void
	{
		$user = new InMemoryUser('jane', 'secret', ['ROLE_USER']);

		$this->assertSame([], $this->createProxy($user, false)->getRoleNames());
		$this->assertSame(['ROLE_USER'], $this->createProxy($user, true)->getRoleNames());
	}

	public function testAuthenticationFlagCanBeSet(): void
	{
		$user = new InMemoryUser('jane', 'secret', ['ROLE_USER']);
		$token = $this->createProxy($user, true);

		$this->assertTrue($token->isAuthenticated());
	}

	public function testSerializationPreservesAuthenticationFlag(): void
	{
		$user = new InMemoryUser('jane', 'secret', ['ROLE_USER']);
		$token = $this->createProxy($user, true);

		$restored = unserialize(serialize($token));

		$this->assertInstanceOf(AuthenticationToken::class, $restored);
		$this->assertTrue($restored->isAuthenticated());
		$this->assertSame('jane', $restored->getUserIdentifier());
	}

	private function createProxy(InMemoryUser $user, bool $authenticated): AuthenticationToken
	{
		$inner = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

		$token = new AuthenticationToken(['ROLE_USER']);
		$token->setAssociatedToken($inner);
		$token->setIsAuthenticated($authenticated);

		return $token;
	}
}
