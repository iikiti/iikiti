<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service\Consent;

require_once __DIR__.'/../../../plugins/consent-management/src/Service/ConsentService.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Entity/ConsentRecord.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Repository/ConsentRecordRepository.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Service/GeoIpResolver.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Service/HeaderGeoIpResolver.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Service/LegalFrameworkResolver.php';

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;
use iikiti\Extension\ConsentManagement\Repository\ConsentRecordRepository;
use iikiti\Extension\ConsentManagement\Service\ConsentService;
use iikiti\Extension\ConsentManagement\Service\HeaderGeoIpResolver;
use iikiti\Extension\ConsentManagement\Service\LegalFrameworkResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
final class ConsentServiceTest extends TestCase
{
	private function service(?User $user = null, ?ConsentRecord $existing = null): ConsentService
	{
		$em = $this->createMock(EntityManagerInterface::class);
		$em->method('persist');
		$em->method('flush');

		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn($user);

		$repository = $this->createMock(ConsentRecordRepository::class);
		$repository->method('findForUser')->willReturn($existing);
		$repository->method('findForSession')->willReturn($existing);

		$audit = $this->createMock(AuditRecorder::class);
		$audit->method('openEvent');
		$audit->method('record');
		$audit->method('closeEvent');

		return new ConsentService(
			$em,
			$security,
			$repository,
			new HeaderGeoIpResolver(),
			new LegalFrameworkResolver(),
			$audit,
			'test-signing-secret',
		);
	}

	public function testRecordCreatesNewConsentForAnonymousSession(): void
	{
		$service = $this->service(null, null);
		$site = new Site();
		$site->setSiteId(7);

		$request = Request::create('/api/privacy/consent', 'POST', [], [], [], ['HTTP_X_GEO_COUNTRY' => 'DE'], json_encode([
			'categories' => ['necessary' => true, 'analytics' => true, 'marketing' => false],
			'session_id' => 'sess-123',
			'version' => 1,
		]));

		$result = $service->record(['categories' => ['analytics' => true, 'marketing' => false], 'session_id' => 'sess-123', 'version' => 1], $request, $site);

		self::assertNotEmpty($result['proofToken']);
		self::assertNotEmpty($result['sessionId']);
		// Unsigned client session_id must not be reused directly; server issues its own session ID
		self::assertNotSame('sess-123', $result['sessionId']);
		self::assertSame(['necessary' => true, 'analytics' => true, 'marketing' => false], $result['categories']);
		self::assertSame('gdpr', $result['legalFramework']);
		self::assertSame(1, $result['version']);
		self::assertFalse($result['stale']);
	}

	public function testGetStateResolvesAuthenticatedUserRecord(): void
	{
		$user = new User();
		(new \ReflectionProperty(\iikiti\CMS\Entity\DbObject::class, 'id'))->setValue($user, 99);
		$existing = new ConsentRecord();
		$existing->setUserId(99);
		$existing->setVersion(1);
		$existing->setCategories(['necessary' => true, 'analytics' => true, 'marketing' => false]);
		$existing->setProofToken('proof-user-99');

		$em = $this->createMock(EntityManagerInterface::class);
		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn($user);
		$repository = $this->createMock(ConsentRecordRepository::class);
		$site = new Site();
		$site->setSiteId(7);
		$repository->expects(self::once())->method('findForUser')->with($site, 99)->willReturn($existing);
		$repository->expects(self::never())->method('findForSession');

		$service = new ConsentService(
			$em,
			$security,
			$repository,
			new HeaderGeoIpResolver(),
			new LegalFrameworkResolver(),
			$this->createMock(AuditRecorder::class),
			'test-signing-secret',
		);

		$state = $service->getState(Request::create('/api/privacy/consent'), $site);

		self::assertTrue($state['hasConsented']);
		self::assertSame('proof-user-99', $state['proofToken']);
		self::assertSame(99, $state['userId']);
		self::assertTrue($state['categories']['analytics']);
	}

	public function testGetStateIgnoresUnsignedSessionQueryAndHonorsSignedCookie(): void
	{
		$existing = new ConsentRecord();
		$existing->setSessionId('server-sid-abc');
		$existing->setVersion(1);
		$existing->setCategories(['necessary' => true, 'analytics' => true, 'marketing' => false]);
		$existing->setProofToken('proof-anon');

		$em = $this->createMock(EntityManagerInterface::class);
		$security = $this->createMock(Security::class);
		$security->method('getUser')->willReturn(null);
		$site = new Site();
		$site->setSiteId(7);
		$repository = $this->createMock(ConsentRecordRepository::class);
		$repository->expects(self::once())->method('findForSession')->with($site, 'server-sid-abc')->willReturn($existing);

		$service = new ConsentService(
			$em,
			$security,
			$repository,
			new HeaderGeoIpResolver(),
			new LegalFrameworkResolver(),
			$this->createMock(AuditRecorder::class),
			'test-signing-secret',
		);

		// Raw query string without signed cookie must NOT look up any session
		$unauthenticatedState = $service->getState(Request::create('/api/privacy/consent?session_id=server-sid-abc'), $site);
		self::assertFalse($unauthenticatedState['hasConsented']);

		// Signed cookie resolves the session record
		$signedRequest = Request::create('/api/privacy/consent', 'GET', [], [
			ConsentService::SESSION_COOKIE_NAME => (string) $service->signSessionId('server-sid-abc', $site),
		]);
		$signedState = $service->getState($signedRequest, $site);
		self::assertTrue($signedState['hasConsented']);
		self::assertSame('proof-anon', $signedState['proofToken']);
	}

	public function testRecordRequiresSessionForAnonymousUser(): void
	{
		$service = $this->service(null, null);
		$site = new Site();
		$site->setSiteId(7);

		$request = Request::create('/api/privacy/consent');
		$this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
		$service->record([], $request, $site);
	}

	public function testGetStateReturnsNotConsentedWhenNoRecord(): void
	{
		$service = $this->service(null, null);
		$site = new Site();
		$site->setSiteId(7);

		$request = Request::create('/api/privacy/consent?session_id=sess-123');
		$state = $service->getState($request, $site);

		self::assertFalse($state['hasConsented']);
		self::assertTrue($state['stale']);
		self::assertSame(0, $state['version']);
		self::assertNull($state['proofToken']);
	}

	public function testBannerRequiredTrueWhenNoRecord(): void
	{
		$service = $this->service(null, null);
		$site = new Site();
		$site->setSiteId(7);

		$request = Request::create('/api/privacy/consent');
		self::assertTrue($service->bannerRequired($request, $site));
	}
}
