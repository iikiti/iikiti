<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\ApiTokenAuthenticator;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Security\GuestApiUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class ApiTokenAuthenticatorGuestTest extends TestCase
{
	public function testGuestCredentialIsAcceptedOnlyForItsPublicSiteRenderRoute(): void
	{
		$manager = new GuestApiTokenManager('test-secret');
		$authenticator = new ApiTokenAuthenticator($this->createStub(ApiTokenRepository::class), $manager);
		$request = Request::create('/api/public/render/pages/about', 'GET');
		$request->headers->set('X-AUTH-TOKEN', $manager->issue('www.example.test'));
		$request->headers->set('X-SITE-HOST', 'www.example.test');

		$passport = $authenticator->authenticate($request);

		self::assertInstanceOf(GuestApiUser::class, $passport->getUser());
	}

	public function testGuestCredentialCannotCallAWriteOrOtherApiOperation(): void
	{
		$manager = new GuestApiTokenManager('test-secret');
		$authenticator = new ApiTokenAuthenticator($this->createStub(ApiTokenRepository::class), $manager);
		$request = Request::create('/api/public/render/pages/about', 'POST');
		$request->headers->set('X-AUTH-TOKEN', $manager->issue('www.example.test'));
		$request->headers->set('X-SITE-HOST', 'www.example.test');
		$this->expectException(CustomUserMessageAuthenticationException::class);

		$authenticator->authenticate($request);
	}

	public function testGuestCredentialCannotBeReplayedForAnotherSite(): void
	{
		$manager = new GuestApiTokenManager('test-secret');
		$authenticator = new ApiTokenAuthenticator($this->createStub(ApiTokenRepository::class), $manager);
		$request = Request::create('/api/public/render/pages/about', 'GET');
		$request->headers->set('X-AUTH-TOKEN', $manager->issue('www.example.test'));
		$request->headers->set('X-SITE-HOST', 'other.example.test');
		$this->expectException(CustomUserMessageAuthenticationException::class);

		$authenticator->authenticate($request);
	}

	public function testRejectsLegacyUserTokensSoTheyCannotOutliveMfaCredentialRotation(): void
	{
		$repository = $this->createMock(ApiTokenRepository::class);
		$repository->expects(self::never())->method('findOneBy');
		$authenticator = new ApiTokenAuthenticator($repository, new GuestApiTokenManager('test-secret'));
		$request = Request::create('/api/admin/menu', 'GET');
		$request->headers->set('X-AUTH-TOKEN', bin2hex(random_bytes(32)));
		$this->expectException(CustomUserMessageAuthenticationException::class);

		$authenticator->authenticate($request);
	}

	public function testAcceptsCurrentVersionUserTokens(): void
	{
		$user = $this->createStub(User::class);
		$user->method('getUserIdentifier')->willReturn('admin');
		$token = $this->createStub(ApiToken::class);
		$token->method('getUser')->willReturn($user);
		$token->method('isExpired')->willReturn(false);
		$repository = $this->createMock(ApiTokenRepository::class);
		$repository->expects(self::once())->method('findOneBy')->with(['token' => ApiToken::TOKEN_PREFIX.'valid-token'])->willReturn($token);
		$authenticator = new ApiTokenAuthenticator($repository, new GuestApiTokenManager('test-secret'));
		$request = Request::create('/api/admin/menu', 'GET');
		$request->headers->set('X-AUTH-TOKEN', ApiToken::TOKEN_PREFIX.'valid-token');

		$passport = $authenticator->authenticate($request);

		self::assertSame('admin', $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier());
	}
}
