<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Event\Subscriber;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Enum\UserRoleEnum;
use iikiti\CMS\Event\Subscriber\UserSiteContextSubscriber;
use iikiti\CMS\Registry\SiteRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\CheckRefreshedUserEvent;

final class UserSiteContextSubscriberTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Ensure default roles (ROLE_USER) are registered on UserRoleEnum.
		class_exists(UserRoleEnum::class);
	}

	public function testUserGetRolesWithoutSiteContextReturnsDefaultAndGlobalRolesOnly(): void
	{
		$user = $this->createUser('alice', 'hash-1', [
			0 => ['ROLE_ADMIN'],
			42 => ['ROLE_SITE_MANAGER'],
		]);

		self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
		self::assertArrayHasKey('User', $user->getRoles(true));
		self::assertArrayHasKey('Admin', $user->getRoles(true));
		self::assertNotContains('ROLE_SITE_MANAGER', $user->getRoles());
	}

	public function testUserGetRolesWithSiteContextIncludesSiteRoles(): void
	{
		$site = $this->siteWithId(42);
		$user = $this->createUser('alice', 'hash-1', [
			0 => [],
			42 => ['ROLE_SITE_MANAGER'],
		]);
		$user->setCurrentSite($site);

		self::assertSame(['ROLE_USER', 'ROLE_SITE_MANAGER'], $user->getRoles());
	}

	public function testCheckRefreshedUserClearsSiteMismatchFlagOnSiteIndependentRefresh(): void
	{
		$site = $this->siteWithId(42);
		$originalUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$originalUser->setCurrentSite($site);

		$refreshedUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$token = new UsernamePasswordToken($originalUser, 'main', ['ROLE_USER', 'ROLE_SITE_MANAGER']);
		$event = new CheckRefreshedUserEvent($token, $originalUser, $refreshedUser, true);

		$this->subscriber()->onCheckRefreshedUser($event);

		self::assertFalse($event->isUserChanged());
		self::assertNull($refreshedUser->getCurrentSite());
		self::assertSame(['ROLE_USER'], $refreshedUser->getRoles());
	}

	public function testCheckRefreshedUserKeepsDeauthenticationWhenSiteRolesChanged(): void
	{
		$site = $this->siteWithId(42);
		$originalUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$originalUser->setCurrentSite($site);

		$refreshedUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_EDITOR']]);
		$token = new UsernamePasswordToken($originalUser, 'main', ['ROLE_USER', 'ROLE_SITE_MANAGER']);
		$event = new CheckRefreshedUserEvent($token, $originalUser, $refreshedUser, true);

		$this->subscriber()->onCheckRefreshedUser($event);

		self::assertTrue($event->isUserChanged());
	}

	public function testCheckRefreshedUserKeepsDeauthenticationWhenPasswordChanged(): void
	{
		$site = $this->siteWithId(42);
		$originalUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$originalUser->setCurrentSite($site);

		$refreshedUser = $this->createUser('alice', 'hash-2', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$token = new UsernamePasswordToken($originalUser, 'main', ['ROLE_USER', 'ROLE_SITE_MANAGER']);
		$event = new CheckRefreshedUserEvent($token, $originalUser, $refreshedUser, true);

		$this->subscriber()->onCheckRefreshedUser($event);

		self::assertTrue($event->isUserChanged());
	}

	public function testCheckRefreshedUserKeepsDeauthenticationWhenUsernameChanged(): void
	{
		$site = $this->siteWithId(42);
		$originalUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$originalUser->setCurrentSite($site);

		$refreshedUser = $this->createUser('bob', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$token = new UsernamePasswordToken($originalUser, 'main', ['ROLE_USER', 'ROLE_SITE_MANAGER']);
		$event = new CheckRefreshedUserEvent($token, $originalUser, $refreshedUser, true);

		$this->subscriber()->onCheckRefreshedUser($event);

		self::assertTrue($event->isUserChanged());
	}

	public function testCheckRefreshedUserKeepsDeauthenticationWhenRefreshedUserHasSiteContext(): void
	{
		$site = $this->siteWithId(42);
		$originalUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$originalUser->setCurrentSite($site);

		$refreshedUser = $this->createUser('alice', 'hash-1', [0 => [], 42 => ['ROLE_SITE_MANAGER']]);
		$refreshedUser->setCurrentSite($site);
		$token = new UsernamePasswordToken($originalUser, 'main', ['ROLE_USER', 'ROLE_SITE_MANAGER']);
		$event = new CheckRefreshedUserEvent($token, $originalUser, $refreshedUser, true);

		$this->subscriber()->onCheckRefreshedUser($event);

		self::assertTrue($event->isUserChanged());
	}

	private function subscriber(): UserSiteContextSubscriber
	{
		return new UserSiteContextSubscriber($this->createStub(SiteRegistry::class));
	}

	private function siteWithId(int $id): Site
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, $id);

		return $site;
	}

	/** @param array<int|string,list<string>> $rolesBySite */
	private function createUser(string $username, string $password, array $rolesBySite): User
	{
		$user = new User();
		$user->setProperty('username', $username);
		$user->setProperty('password', $password);
		$user->setProperty('roles', $rolesBySite);

		return $user;
	}
}
