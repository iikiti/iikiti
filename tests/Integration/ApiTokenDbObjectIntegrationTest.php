<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Security\ApiTokenAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiTokenDbObjectIntegrationTest extends KernelTestCase
{
	private EntityManagerInterface $entityManager;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
		$this->entityManager->getConnection()->beginTransaction();
	}

	protected function tearDown(): void
	{
		if ($this->entityManager->getConnection()->isTransactionActive()) {
			$this->entityManager->getConnection()->rollBack();
		}
		self::resetSiteRegistry();
		parent::tearDown();
	}

	private static function resetSiteRegistry(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}

	public function testTokenIsStoredAsGlobalObjectAndAuthenticatesThroughRepository(): void
	{
		$now = new \DateTimeImmutable();
		$user = new User();
		$user->setSiteId(0);
		$user->setCreatorId(0);
		$user->setCreatedDate($now);
		$user->setProperties(new ArrayCollection());
		$user->setProperty('username', 'token-test-'.bin2hex(random_bytes(4)));
		$this->entityManager->persist($user);
		$this->entityManager->flush();

		$tokenValue = bin2hex(random_bytes(32));
		$token = new ApiToken();
		$token->setToken($tokenValue);
		$token->setSiteId(0);
		$token->setCreatedDate($now);
		$token->setUser($user);
		$token->setExpiresAt($now->modify('+1 hour'));
		$this->entityManager->persist($token);
		$this->entityManager->flush();

		self::assertInstanceOf(DbObject::class, $token);
		self::assertSame(0, $token->getSiteId());
		self::assertSame((string) $user->getId(), (string) $token->getCreatorId());
		self::assertInstanceOf(ApiTokenRepository::class, $this->entityManager->getRepository(ApiToken::class));

		$this->entityManager->clear();
		$authenticator = self::getContainer()->get(ApiTokenAuthenticator::class);
		$passport = $authenticator->authenticate(Request::create('/', 'GET', [], [], [], ['HTTP_X_AUTH_TOKEN' => $tokenValue]));
		$badge = $passport->getBadge(UserBadge::class);

		self::assertInstanceOf(UserBadge::class, $badge);
		self::assertSame($user->getUserIdentifier(), $badge->getUserIdentifier());
	}
}
