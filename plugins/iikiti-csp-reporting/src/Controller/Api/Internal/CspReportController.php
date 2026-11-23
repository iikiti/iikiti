<?php

declare(strict_types=1);

namespace iikiti\Extension\CspReporting\Controller\Api\Internal;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\Extension\CspReporting\Entity\CspReport;
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
#[RequiresPlugin('iikiti-csp-reporting')]
#[Route('/api/internal/csp-reports', name: 'api_internal_csp_reports', methods: ['POST'])]
final class CspReportController extends AppController
{
	public const LIMIT = 30;
	public const WINDOW_SECONDS = 60;
	public const RATE_LIMIT_RULE_ID = 'core:csp-reports';
	public const MAX_PAYLOAD_BYTES = 16_384;

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

		$rawContent = $request->getContent();
		if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
			return new JsonResponse(['error' => 'CSP report payload too large.'], 413);
		}

		$data = json_decode($rawContent, true);
		$report = is_array($data) ? ($data['csp-report'] ?? $data) : null;
		if (!is_array($report)) {
			return new JsonResponse(['error' => 'Invalid CSP report.'], 400);
		}

		$entity = new CspReport();
		if (SiteRegistry::hasCurrent()) {
			$entity->setSiteId(SiteRegistry::getCurrent()->getId());
		}
		$entity->setClientIp($this->boundedString($ip, 64));
		$entity->setUserAgent($this->boundedString($request->headers->get('User-Agent'), 255));
		$entity->setDocumentUri($this->boundedString($report['document-uri'] ?? null, 2048));
		$entity->setReferrer($this->boundedString($report['referrer'] ?? null, 2048));
		$entity->setBlockedUri($this->boundedString($report['blocked-uri'] ?? null, 2048));
		$entity->setViolatedDirective($this->boundedString($report['violated-directive'] ?? null, 255));
		$entity->setEffectiveDirective($this->boundedString($report['effective-directive'] ?? null, 255));
		$entity->setOriginalPolicy($this->boundedString($report['original-policy'] ?? null, 1024));
		$entity->setStatusCode($this->normalizedStatusCode($report['status-code'] ?? null));
		$entity->setReportBody($report);

		try {
			$this->entityManager->persist($entity);
			$this->entityManager->flush();
		} catch (\Throwable) {
			// Reports are best-effort; never break the browser's response.
		}

		return new JsonResponse(['status' => 'ok'], 200);
	}

	private function boundedString(mixed $value, int $maxLength): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		$trimmed = trim($value);
		if ('' === $trimmed) {
			return null;
		}

		return mb_substr($trimmed, 0, $maxLength);
	}

	private function normalizedStatusCode(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value >= 0 && $value <= 999 ? $value : null;
		}
		if (is_string($value) && ctype_digit($value)) {
			$code = (int) $value;

			return $code >= 0 && $code <= 999 ? $code : null;
		}

		return null;
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
