<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\FrontendConfigProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

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
		$this->assertSame('room/template:7', $config['room']);
		$this->assertSame(['sm' => 640, 'md' => 768, 'lg' => 1024, 'xl' => 1280], $config['breakpoints']);
		$this->assertSame(10, $config['notifications']['durationSeconds']);
		$this->assertSame('bottom-right', $config['notifications']['position']);
		$this->assertSame([], $config['plugins']);
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
