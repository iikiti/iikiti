<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SiteRegistryConsumerTest extends TestCase
{
	protected function setUp(): void
	{
		$this->resetSiteRegistry();
		parent::setUp();
	}

	protected function tearDown(): void
	{
		$this->resetSiteRegistry();
		parent::tearDown();
	}

	private function resetSiteRegistry(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}

	public function testConsumerRoleDoesNotResolveSiteFromTheLocalDatabase(): void
	{
		$requests = new RequestStack();
		$requests->push(Request::create('https://www.example.test/'));
		$database = $this->createMock(ManagerRegistry::class);
		$database->expects(self::never())->method('getManager');

		new SiteRegistry($requests, $database, new GuestApiTokenManager('test-secret'), 'consumer');

		self::assertFalse(SiteRegistry::hasCurrent());
	}
}
