<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\CspReport;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepts Content-Security-Policy violation reports from browsers.
 *
 * Reports are rate-limited per client IP through the shared firewall rate limiter
 * (rule {@see self::RATE_LIMIT_RULE_ID}) and stored for admin review. The endpoint is
 * intentionally reachable without authentication so browsers can post reports; it is
 * write-only (no listing here).
 */
#[Route('/api/internal/csp-reports', name: 'api_internal_csp_reports', methods: ['POST'])]
final class CspReportController extends AppController
{
	public const LIMIT = 30;
	public const WINDOW_SECONDS = 60;
	public const RATE_LIMIT_RULE_ID = 'core:csp-reports';

	public function __construct(
		protected \Symfony\Bundle\SecurityBundle\Security $security,
		protected \Psr\Container\ContainerInterface $container,
		private readonly FirewallRateLimiterManager $rateLimiter,
		private readonly EntityManagerInterface $entityManager,
	) {
		parent::__construct($security, $container);
	}

	public function __invoke(Request $request): JsonResponse
	{
		$ip = $request->getClientIp() ?? 'unknown';
		if ($this->isRateLimited($request, $ip)) {
			throw new TooManyRequestsHttpException(self::WINDOW_SECONDS, sprintf('CSP report rate limit exceeded. Retry after %d seconds.', self::WINDOW_SECONDS));
		}

		$data = json_decode($request->getContent(), true);
		$report = $data['csp-report'] ?? $data;
		if (!is_array($report)) {
			return new JsonResponse(['error' => 'Invalid CSP report.'], 400);
		}

		$entity = new CspReport();
		if (SiteRegistry::hasCurrent()) {
			$entity->setSiteId(SiteRegistry::getCurrent()->getId());
		}
		$entity->setClientIp($ip);
		$entity->setUserAgent($request->headers->get('User-Agent'));
		$entity->setDocumentUri($report['document-uri'] ?? null);
		$entity->setReferrer($report['referrer'] ?? null);
		$entity->setBlockedUri($report['blocked-uri'] ?? null);
		$entity->setViolatedDirective($report['violated-directive'] ?? null);
		$entity->setEffectiveDirective($report['effective-directive'] ?? null);
		$entity->setOriginalPolicy($report['original-policy'] ?? null);
		$entity->setStatusCode($report['status-code'] ?? null);
		$entity->setReportBody($report);

		try {
			$this->entityManager->persist($entity);
			$this->entityManager->flush();
		} catch (\Throwable $e) {
			// Reports are best-effort; never break the browser's response.
		}

		return new JsonResponse(['status' => 'ok'], 200);
	}

	/**
	 * Evaluates the shared `core:csp-reports` rule for this client. Fails open: a limiter
	 * outage must never drop browser reports.
	 */
	private function isRateLimited(Request $request, string $ip): bool
	{
		try {
			$decisions = $this->rateLimiter->evaluate(
				[self::cspRateLimitRule()],
				'pre_auth',
				new FirewallRateLimitContext(
					clientIp: $ip,
					method: $request->getMethod(),
					path: $request->getPathInfo(),
					routeGroup: 'api',
					authenticated: false,
				),
			);
		} catch (\Throwable) {
			return false;
		}

		foreach ($decisions as $decision) {
			if (!$decision->accepted) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string,mixed> */
	private static function cspRateLimitRule(): array
	{
		return [
			'id' => self::RATE_LIMIT_RULE_ID,
			'name' => 'CSP report submissions',
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'required',
			'action' => 'throttle',
			'algorithm' => 'sliding_window',
			'limit' => self::LIMIT,
			'interval' => self::WINDOW_SECONDS,
			'keyBy' => 'ip',
			'match' => ['methods' => ['POST'], 'pathPrefix' => '', 'pathRegex' => null, 'routeGroup' => 'custom', 'authenticated' => 'any'],
		];
	}
}
