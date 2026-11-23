<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Service\CspDirectiveContributorInterface;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use iikiti\Extension\CspReporting\Controller\Api\Internal\CspReportController;
use iikiti\Extension\CspReporting\Security\CspReportingDirectiveContributor;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CspReportingPluginTest extends TestCase
{
	public function testContributesReportUriDirectiveWhenPluginIsActiveAndOmitsWhenInactive(): void
	{
		$barePolicy = new SecurityHeaderPolicy('prod');
		$bareRendered = $barePolicy->renderHeaders([], 'on');
		self::assertStringNotContainsString('report-uri', $bareRendered[SecurityHeaderPolicy::HEADER_CSP]);

		$contributor = new CspReportingDirectiveContributor();
		$policyWithContributor = new SecurityHeaderPolicy('prod', [$contributor]);
		$withRendered = $policyWithContributor->renderHeaders([], 'on');
		self::assertStringContainsString('report-uri /api/internal/csp-reports', $withRendered[SecurityHeaderPolicy::HEADER_CSP]);

		$registry = new PluginRegistry();
		$registry->add(PluginManifest::fromArray([
			'name' => 'CSP Reporting',
			'slug' => 'iikiti-csp-reporting',
			'namespace' => 'iikiti\\Extension\\CspReporting',
			'bundle_class' => 'iikiti\\Extension\\CspReporting\\CspReportingBundle',
			'version' => '1.0.0',
			'iikiti_version' => '^1.0',
			'activation_mode' => 'opt_in',
			'scope' => 'application',
		]));
		$siteRegistry = new SiteRegistry(
			new RequestStack(),
			$this->createStub(ManagerRegistry::class),
			new GuestApiTokenManager('test-secret'),
			'api',
		);
		$checker = new PluginActivationChecker($registry, $siteRegistry, $this->createStub(ApplicationRegistry::class));
		$inactiveContributor = new CspReportingDirectiveContributor($checker);
		$inactivePolicy = new SecurityHeaderPolicy('prod', [$inactiveContributor]);
		$inactiveRendered = $inactivePolicy->renderHeaders([], 'on');
		self::assertStringNotContainsString('report-uri', $inactiveRendered[SecurityHeaderPolicy::HEADER_CSP]);
	}

	public function testSecurityHeaderPolicySanitizesContributorDirectives(): void
	{
		$malicious = new class implements CspDirectiveContributorInterface {
			public function getDirectives(string $environment): array
			{
				return [
					'invalid directive!' => ["'self'"],
					'report-uri' => ["/safe", "/bad; script-src 'unsafe-inline'", "/bad2\r\nX-Inject: 1"],
				];
			}
		};

		$csp = (new SecurityHeaderPolicy('prod', [$malicious]))->renderHeaders([], 'on')[SecurityHeaderPolicy::HEADER_CSP];
		self::assertStringContainsString('report-uri /safe', $csp);
		self::assertStringNotContainsString('unsafe-inline', $csp);
		self::assertStringNotContainsString('X-Inject', $csp);
		self::assertStringNotContainsString('invalid directive', $csp);
	}

	public function testCspReportControllerRejectsOversizedPayloadsAndMalformedFields(): void
	{
		$controller = new CspReportController(
			$this->createStub(Security::class),
			$this->createStub(ContainerInterface::class),
			new FirewallRateLimiterManager(new ArrayAdapter()),
			$this->createStub(EntityManagerInterface::class),
		);

		$tooLarge = Request::create(
			'/api/internal/csp-reports',
			'POST',
			content: str_repeat('a', CspReportController::MAX_PAYLOAD_BYTES + 1),
		);
		self::assertSame(413, $controller($tooLarge)->getStatusCode());

		$malformed = Request::create(
			'/api/internal/csp-reports',
			'POST',
			content: json_encode([
				'csp-report' => [
					'document-uri' => ['not-a-string'],
					'status-code' => 'not-an-int',
				],
			], JSON_THROW_ON_ERROR),
		);
		self::assertSame(200, $controller($malformed)->getStatusCode());
	}
}
