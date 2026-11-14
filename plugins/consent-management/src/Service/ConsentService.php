<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Site;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;
use iikiti\Extension\ConsentManagement\Repository\ConsentRecordRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Consent business logic: read and record consent preferences per user or
 * session, scoped to a site, with geo-targeted legal-framework resolution,
 * signal auto-selection, proof-of-consent capture and audit logging.
 */
final class ConsentService
{
	/** @var array<string,bool> */
	public const DEFAULT_CATEGORIES = ['necessary' => true, 'analytics' => false, 'marketing' => false];

	public const CONFIG_VERSION_KEY = 'version';
	public const CONFIG_DEFAULT_CATEGORIES_KEY = 'default_categories';
	public const CONFIG_FRAMEWORK_OVERRIDE_KEY = 'framework_override';

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly Security $security,
		private readonly ConsentRecordRepository $repository,
		private readonly GeoIpResolver $geoIpResolver,
		private readonly LegalFrameworkResolver $frameworkResolver,
		private readonly AuditRecorder $auditRecorder,
	) {
	}

	/**
	 * Return the current consent state for the acting user or session.
	 *
	 * @return array{
	 *     hasConsented: bool,
	 *     categories: array<string,bool>,
	 *     version: int,
	 *     configVersion: int,
	 *     stale: bool,
	 *     legalFramework: string,
	 *     geoCountry: string|null,
	 *     signals: array{ gpc?: bool, dnt?: bool }|null,
	 *     proofToken: string|null,
	 *     consentedAt: string|null,
	 *     userId: int|string|null,
	 *     sessionId: string|null
	 * }
	 */
	public function getState(Request $request, Site $site): array
	{
		$config = $this->config($site);
		$configVersion = (int) ($config[self::CONFIG_VERSION_KEY] ?? 1);

		$sessionId = $request->request->get('session_id') ?? $request->query->get('session_id');
		$record = $this->findRecord($site, $sessionId);

		if (null === $record) {
			return $this->emptyState($configVersion, $site);
		}

		$stale = $record->getVersion() !== $configVersion;

		return [
			'hasConsented' => !$stale,
			'categories' => $record->getCategories(),
			'version' => $record->getVersion(),
			'configVersion' => $configVersion,
			'stale' => $stale,
			'legalFramework' => $record->getLegalFramework(),
			'geoCountry' => $record->getGeoCountry(),
			'signals' => $record->getSignals(),
			'proofToken' => $record->getProofToken(),
			'consentedAt' => $record->getConsentedAt()?->format(\DateTimeInterface::ATOM),
			'userId' => $record->getUserId(),
			'sessionId' => $record->getSessionId(),
		];
	}

	/**
	 * Record or update consent preferences.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{proofToken: string, categories: array<string,bool>, legalFramework: string, version: int, stale: bool}
	 * @throws AccessDeniedHttpException
	 */
	public function record(array $payload, Request $request, Site $site): array
	{
		$config = $this->config($site);
		$configVersion = (int) ($config[self::CONFIG_VERSION_KEY] ?? 1);

		$categories = $this->normalizeCategories($payload['categories'] ?? []);
		$sessionId = $payload['session_id'] ?? null;
		$clientVersion = isset($payload['version']) ? (int) $payload['version'] : null;

		$user = $this->security->getUser();
		$userId = $user ? ($user->getId() ?? null) : null;
		if (null === $sessionId && null === $userId) {
			// Anonymous consent requires a client-generated session id so the
			// record can be looked up on subsequent requests.
			throw new AccessDeniedHttpException('A session_id is required for anonymous consent.');
		}

		$record = $this->findRecord($site, $sessionId, $userId);

		$isNew = null === $record;
		if ($isNew) {
			$record = new ConsentRecord();
			$record->setSiteId($site->getId() ?? 0);
			$record->setCreatedDate(new \DateTimeImmutable());
			$record->setCreatorId($userId ?? 0);
			$record->setUserId($userId);
			$record->setSessionId($sessionId);
		}

		$before = $isNew ? null : $record->getCategories();

		$record->setCategories($categories);
		$record->setVersion($configVersion);
		$geoCountry = $this->geoIpResolver->resolveCountry($request);
		$record->setGeoCountry($geoCountry);
		$record->setLegalFramework(
			$config[self::CONFIG_FRAMEWORK_OVERRIDE_KEY] ?? $this->frameworkResolver->resolve($geoCountry)
		);
		$record->setSignals($this->detectSignals($request));
		$record->setProofToken($record->getProofToken() ?? $this->newProofToken());
		$record->setConsentedAt(new \DateTimeImmutable());
		$record->setIpAddress($request->getClientIp());
		$record->setUserAgent($request->headers->get('User-Agent'));
		if (null !== $userId && null === $record->getUserId()) {
			$record->setUserId($userId);
		}
		if (null !== $sessionId && null === $record->getSessionId()) {
			$record->setSessionId($sessionId);
		}

		$this->entityManager->persist($record);
		$this->entityManager->flush();

		$this->auditConsentChange($record, $before, $isNew, $clientVersion === $configVersion);

		return [
			'proofToken' => $record->getProofToken(),
			'categories' => $record->getCategories(),
			'legalFramework' => $record->getLegalFramework(),
			'version' => $record->getVersion(),
			'stale' => false,
		];
	}

	/**
	 * Whether the consent banner must (re)show for the current user/session:
	 * no record, or record version is stale against the configured version.
	 */
	public function bannerRequired(Request $request, Site $site): bool
	{
		$config = $this->config($site);
		$configVersion = (int) ($config[self::CONFIG_VERSION_KEY] ?? 1);

		$sessionId = $request->request->get('session_id') ?? $request->query->get('session_id');
		$record = $this->findRecord($site, $sessionId);

		return null === $record || $record->getVersion() !== $configVersion;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function config(Site $site): array
	{
		$configuration = $site->getConfiguration();
		$data = (array) $configuration->getPluginConfiguration('consent-management');
		if (!isset($data[self::CONFIG_VERSION_KEY])) {
			$data[self::CONFIG_VERSION_KEY] = 1;
		}
		if (!isset($data[self::CONFIG_DEFAULT_CATEGORIES_KEY])) {
			$data[self::CONFIG_DEFAULT_CATEGORIES_KEY] = self::DEFAULT_CATEGORIES;
		}

		return $data;
	}

	/**
	 * @return ConsentRecord|null
	 */
	private function findRecord(Site $site, ?string $sessionId, int|string|null $userId = null): ?ConsentRecord
	{
		if (null !== $userId) {
			return $this->repository->findForUser($site, $userId);
		}
		if (null !== $sessionId) {
			return $this->repository->findForSession($site, $sessionId);
		}

		return null;
	}

	/**
	 * @return array<string,bool>
	 */
	private function normalizeCategories(mixed $categories): array
	{
		if (!is_array($categories)) {
			return self::DEFAULT_CATEGORIES;
		}

		return [
			'necessary' => true,
			'analytics' => (bool) ($categories['analytics'] ?? false),
			'marketing' => (bool) ($categories['marketing'] ?? false),
		];
	}

	/**
	 * @return array{ gpc?: bool, dnt?: bool }
	 */
	private function detectSignals(Request $request): array
	{
		$signals = [];
		$gpc = $request->headers->get('Sec-GPC');
		if ('1' === $gpc || 'true' === $gpc) {
			$signals['gpc'] = true;
		}
		$dnt = $request->headers->get('DNT');
		if ('1' === $dnt) {
			$signals['dnt'] = true;
		}

		return $signals;
	}

	/**
	 * @return array{
	 *     hasConsented: bool,
	 *     categories: array<string,bool>,
	 *     version: int,
	 *     configVersion: int,
	 *     stale: bool,
	 *     legalFramework: string,
	 *     geoCountry: null,
	 *     signals: null,
	 *     proofToken: null,
	 *     consentedAt: null,
	 *     userId: int|string|null,
	 *     sessionId: string|null
	 * }
	 */
	private function emptyState(int $configVersion, Site $site): array
	{
		return [
			'hasConsented' => false,
			'categories' => self::DEFAULT_CATEGORIES,
			'version' => 0,
			'configVersion' => $configVersion,
			'stale' => true,
			'legalFramework' => $this->frameworkResolver->resolve(null),
			'geoCountry' => null,
			'signals' => null,
			'proofToken' => null,
			'consentedAt' => null,
			'userId' => null,
			'sessionId' => null,
		];
	}

	private function newProofToken(): string
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * Audit a consent change (grant / update / withdraw).
	 */
	private function auditConsentChange(
		ConsentRecord $record,
		?array $before,
		bool $isNew,
		bool $clientVersionCurrent
	): void {
		$action = $isNew ? 'consent.granted' : 'consent.updated';
		$summary = sprintf(
			'Consent %s for %s (proof %s, framework %s)',
			$isNew ? 'granted' : 'updated',
			$record->getUserId() ? 'user '.$record->getUserId() : 'session '.$record->getSessionId(),
			$record->getProofToken(),
			$record->getLegalFramework()
		);

		$this->auditRecorder->openEvent(
			$action,
			$summary,
			['proof_token' => $record->getProofToken(), 'site_id' => $record->getSiteId()]
		);

		$this->auditRecorder->record(
			$summary,
			$isNew ? 'created' : 'updated',
			'ConsentRecord',
			$record->getId(),
			$before,
			$record->getCategories(),
			[
				'legal_framework' => $record->getLegalFramework(),
				'geo_country' => $record->getGeoCountry(),
				'signals' => $record->getSignals(),
				'proof_token' => $record->getProofToken(),
				'ip_address' => $record->getIpAddress(),
				'client_version_current' => $clientVersionCurrent,
			]
		);

		$this->auditRecorder->closeEvent();
	}
}
