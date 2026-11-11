<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\UserGroupRepository;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\FrontendConfigProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FrontendConfigProviderTest extends TestCase
{
	public function testBuildReturnsNullWhenNoUser(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(null);
		$provider = $this->provider($security);

		$this->assertNull($provider->build(1, 'template', null));
	}

	public function testBuildReturnsNullWhenUserLacksEditPermission(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($this->createStub(User::class));
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(false);
		$provider = $this->provider($security, $checker);

		$this->assertNull($provider->build(1, 'template'));
	}

	public function testBuildReturnsConfigWhenAuthorized(): void
	{
		$security = $this->createStub(Security::class);
		$user = $this->createStub(User::class);
		$security->method('getUser')->willReturn($user);
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('ephemeral-token');
		$tokenManager = $this->createStub(ApiTokenManager::class);
		$tokenManager->method('getOrCreateToken')->willReturn($token);
		$provider = $this->provider($security, $checker, $tokenManager);

		$config = $provider->build(7, 'template', $user);

		$this->assertNotNull($config);
		$this->assertTrue($config['canEdit']);
		$this->assertTrue($config['canPublish']);
		$this->assertSame('ephemeral-token', $config['apiToken']);
		$this->assertSame('en', $config['locale']);
		$this->assertSame('/api', $config['apiBase']);
		$this->assertSame('room/template:7', $config['room']);
		$this->assertSame(['sm' => 640, 'md' => 768, 'lg' => 1024, 'xl' => 1280], $config['breakpoints']);
		$this->assertSame(10, $config['notifications']['durationSeconds']);
		$this->assertSame('bottom-right', $config['notifications']['position']);
		$this->assertSame([], $config['plugins']);
		$this->assertSame([], $config['cacheGroups']);
	}

	public function testBuildProvidesSiteScopedUserGroupOptions(): void
	{
		$security = $this->createStub(Security::class);
		$user = $this->createStub(User::class);
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('ephemeral-token');
		$tokenManager = $this->createStub(ApiTokenManager::class);
		$tokenManager->method('getOrCreateToken')->willReturn($token);
		$site = new Site();
		(new \ReflectionClass(\iikiti\CMS\Entity\DbObject::class))->getProperty('id')->setValue($site, 11);
		new SiteRegistry(new RequestStack(), $this->createStub(ManagerRegistry::class), new GuestApiTokenManager('test-secret'), 'api');
		SiteRegistry::pushSite($site);

		$group = new UserGroup();
		$groupReflection = new \ReflectionClass(\iikiti\CMS\Entity\DbObject::class);
		$groupReflection->getProperty('id')->setValue($group, 27);
		$groupReflection->getProperty('properties')->setValue($group, new ArrayCollection());
		$group->setLabel('Members');
		$groups = $this->createMock(UserGroupRepository::class);
		$groups->expects(self::once())->method('findAvailableForSite')->with(11)->willReturn([$group]);
		$provider = new FrontendConfigProvider(
			$security,
			$checker,
			$tokenManager,
			$this->createStub(PluginRegistry::class),
			userGroups: $groups,
		);

		try {
			$config = $provider->build(7, 'template', $user);
		} finally {
			SiteRegistry::popSite();
		}

		self::assertNotNull($config);
		self::assertSame([['id' => '27', 'label' => 'Members']], $config['cacheGroups']);
	}

	public function testBuildCarriesAnIndependentSettingsTarget(): void
	{
		$security = $this->createStub(Security::class);
		$user = $this->createStub(User::class);
		$security->method('getUser')->willReturn($user);
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('ephemeral-token');
		$tokenManager = $this->createStub(ApiTokenManager::class);
		$tokenManager->method('getOrCreateToken')->willReturn($token);
		$provider = $this->provider($security, $checker, $tokenManager);

		$config = $provider->build(7, 'template', $user, 5, [
			'type' => 'page',
			'id' => 42,
			'label' => 'Page Settings',
			'values' => ['seoTitle' => 'Home'],
			'version' => 3,
			'hasDraft' => true,
		]);

		$this->assertNotNull($config);
		$this->assertSame('template', $config['contextType']);
		$this->assertSame(7, $config['contextId']);
		$this->assertSame('page', $config['settingsContextType']);
		$this->assertSame(42, $config['settingsContextId']);
		$this->assertSame('Page Settings', $config['settingsLabel']);
		$this->assertSame(['seoTitle' => 'Home'], $config['settingsValues']);
		$this->assertSame(3, $config['settingsVersion']);
		$this->assertTrue($config['settingsHasDraft']);
	}

	public function testBuildUsesTheConfiguredRemoteApiOrigin(): void
	{
		$security = $this->createStub(Security::class);
		$user = $this->createStub(User::class);
		$security->method('getUser')->willReturn($user);
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('ephemeral-token');
		$tokenManager = $this->createStub(ApiTokenManager::class);
		$tokenManager->method('getOrCreateToken')->willReturn($token);

		$provider = new FrontendConfigProvider(
			$security,
			$checker,
			$tokenManager,
			$this->createStub(PluginRegistry::class),
			apiBaseUrl: 'https://api.example.test/',
		);

		$config = $provider->build(7, 'template', $user);
		self::assertSame('https://api.example.test/api', $config['apiBase']);
	}

	public function testBuildCarriesTheOriginalSiteContextForRemoteApiCalls(): void
	{
		$security = $this->createStub(Security::class);
		$user = $this->createStub(User::class);
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);
		$token = $this->createStub(ApiToken::class);
		$token->method('getToken')->willReturn('ephemeral-token');
		$tokenManager = $this->createStub(ApiTokenManager::class);
		$tokenManager->method('getOrCreateToken')->willReturn($token);
		$request = Request::create('https://api.example.test/api/public/render/pages/home');
		$request->headers->set('X-SITE-HOST', 'www.example.test');
		$request->headers->set('X-SITE-SCHEME', 'https');
		$requests = new RequestStack();
		$requests->push($request);

		$provider = new FrontendConfigProvider(
			$security,
			$checker,
			$tokenManager,
			$this->createStub(PluginRegistry::class),
			requestStack: $requests,
		);

		$config = $provider->build(7, 'template', $user);

		self::assertSame('www.example.test', $config['apiSiteHost']);
		self::assertSame('https', $config['apiSiteScheme']);
	}

	private function provider(
		Security $security,
		?PermissionChecker $checker = null,
		?ApiTokenManager $tokenManager = null,
	): FrontendConfigProvider {
		return new FrontendConfigProvider(
			$security,
			$checker ?? $this->createStub(PermissionChecker::class),
			$tokenManager ?? $this->createStub(ApiTokenManager::class),
			$this->createStub(PluginRegistry::class),
		);
	}
}
