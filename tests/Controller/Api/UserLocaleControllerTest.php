<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\Api\UserLocaleController;
use iikiti\CMS\Entity\Object\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class UserLocaleControllerTest extends TestCase
{
	public function testSavesAndReturnsTheCanonicalPreferredLocale(): void
	{
		$user = new User();
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$response = (new UserLocaleController($entityManager, $security))->save(
			Request::create('/api/user/locale', 'POST', [], [], [], [], '{"locale":"fr-ca"}'),
		);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame(['locale' => 'fr-CA'], json_decode((string) $response->getContent(), true));
	}

	public function testRejectsMalformedLocaleValues(): void
	{
		$user = new User();
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('flush');

		$response = (new UserLocaleController($entityManager, $security))->save(
			Request::create('/api/user/locale', 'POST', [], [], [], [], '{"locale":"en_US"}'),
		);

		self::assertSame(400, $response->getStatusCode());
	}

	public function testRequiresAuthentication(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(null);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('flush');

		$response = (new UserLocaleController($entityManager, $security))->save(
			Request::create('/api/user/locale', 'POST', [], [], [], [], '{"locale":"en-GB"}'),
		);

		self::assertSame(401, $response->getStatusCode());
	}
}
