<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\CspReport;
use iikiti\CMS\Registry\SiteRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepts Content-Security-Policy violation reports from browsers.
 *
 * Reports are rate-limited per client IP (via a cache counter) and stored for
 * admin review. The endpoint is intentionally reachable without authentication
 * so browsers can post reports; it is write-only (no listing here).
 */
#[Route('/api/internal/csp-reports', name: 'api_internal_csp_reports', methods: ['POST'])]
final class CspReportController extends AppController
{
	public const LIMIT = 30;
	public const WINDOW_SECONDS = 60;

	public function __construct(
		protected \Symfony\Bundle\SecurityBundle\Security $security,
		protected \Psr\Container\ContainerInterface $container,
		#[Autowire('cache.app')]
		private readonly CacheItemPoolInterface $cache,
		private readonly EntityManagerInterface $entityManager,
	) {
		parent::__construct($security, $container);
	}

	public function __invoke(Request $request): JsonResponse
	{
		$ip = $request->getClientIp() ?? 'unknown';
		$key = 'csp_reports.'.md5($ip);
		$rateLimited = false;
		try {
			$item = $this->cache->getItem($key);
			if (!$item->isHit()) {
				$item->set(1);
				$item->expiresAfter(self::WINDOW_SECONDS);
				$this->cache->save($item);
			} else {
				$count = (int) $item->get();
				if ($count >= self::LIMIT) {
					$rateLimited = true;
				} else {
					$item->set($count + 1);
					$this->cache->save($item);
				}
			}
		} catch (InvalidArgumentException $e) {
			// Cache unavailable; do not block report submission.
		} catch (\Throwable $e) {
			// Cache failure; do not block report submission.
		}

		if ($rateLimited) {
			throw new TooManyRequestsHttpException(sprintf('CSP report rate limit exceeded. Retry after %d seconds.', self::WINDOW_SECONDS));
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
}
