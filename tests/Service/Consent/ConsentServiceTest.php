<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service\Consent;

require_once __DIR__.'/../../../plugins/consent-management/src/Service/ConsentService.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Entity/ConsentRecord.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Repository/ConsentRecordRepository.php';
require_once __DIR__.'/../../../plugins/consent-management/src/Service/GeoIpResolver.php';
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
		self::assertSame(['necessary' => true, 'analytics' => true, 'marketing' => false], $result['categories']);
		self::assertSame('gdpr', $result['legalFramework']);
		self::assertSame(1, $result['version']);
		self::assertFalse($result['stale']);
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
