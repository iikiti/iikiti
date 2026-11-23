<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;
use iikiti\Extension\ConsentManagement\Repository\ConsentRecordRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
	public const SESSION_COOKIE_NAME = 'iikiti_consent_sid';
	private const SESSION_ID_PATTERN = '/^[A-Za-z0-9._-]{8,128}$/D';

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly Security $security,
		private readonly ConsentRecordRepository $repository,
		private readonly GeoIpResolver $geoIpResolver,
		private readonly LegalFrameworkResolver $frameworkResolver,
		private readonly AuditRecorder $auditRecorder,
		#[Autowire('%kernel.secret%')]
		private readonly string $signingSecret = '',
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

		$user = $this->security->getUser();
		$userId = $user instanceof User ? $user->getId() : null;
		$sessionId = null === $userId ? $this->resolveSignedSessionId($request, $site) : null;
		$record = $this->findRecord($site, $sessionId, $userId);
		if (null !== $record && null === $userId && null !== $record->getUserId()) {
			$record = null;
		}

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
	 * @return array{proofToken: string, categories: array<string,bool>, legalFramework: string, version: int, configVersion: int, stale: bool, sessionId: ?string}
	 * @throws AccessDeniedHttpException
	 */
	public function record(array $payload, Request $request, Site $site): array
	{
		$config = $this->config($site);
		$configVersion = (int) ($config[self::CONFIG_VERSION_KEY] ?? 1);

		$categories = $this->normalizeCategories($payload['categories'] ?? []);
		$rawPayloadSessionId = $payload['session_id'] ?? null;
		$clientVersion = isset($payload['version']) ? (int) $payload['version'] : null;

		$user = $this->security->getUser();
		$userId = $user instanceof User ? $user->getId() : null;
		$verifiedCookieSessionId = $this->resolveSignedSessionId($request, $site);

		if (null === $userId) {
			if (null !== $verifiedCookieSessionId) {
				$sessionId = $verifiedCookieSessionId;
				$record = $this->findRecord($site, $sessionId, null);
				// Never allow an anonymous cookie to overwrite a record owned by a user.
				if (null !== $record && null !== $record->getUserId()) {
					$record = null;
					$sessionId = bin2hex(random_bytes(16));
				}
			} else {
				if (!is_string($rawPayloadSessionId) || 1 !== preg_match(self::SESSION_ID_PATTERN, $rawPayloadSessionId)) {
					throw new AccessDeniedHttpException('A valid session_id is required for anonymous consent.');
				}
				// Issue a fresh server-owned session ID when no signed cookie is present so
				// an unauthenticated caller cannot overwrite another visitor's consent record.
				$sessionId = bin2hex(random_bytes(16));
				$record = null;
			}
		} else {
			$sessionId = $verifiedCookieSessionId;
			if (null === $sessionId && is_string($rawPayloadSessionId) && 1 === preg_match(self::SESSION_ID_PATTERN, $rawPayloadSessionId)) {
				$sessionId = $rawPayloadSessionId;
			}
			$record = $this->findRecord($site, null, $userId);
		}

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
			'configVersion' => $configVersion,
			'stale' => false,
			'sessionId' => $record->getSessionId(),
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

		$user = $this->security->getUser();
		$userId = $user instanceof User ? $user->getId() : null;
		$sessionId = null === $userId ? $this->resolveSignedSessionId($request, $site) : null;
		$record = $this->findRecord($site, $sessionId, $userId);
		if (null !== $record && null === $userId && null !== $record->getUserId()) {
			$record = null;
		}

		return null === $record || $record->getVersion() !== $configVersion;
	}

	public function signSessionId(string $sessionId, Site $site): ?string
	{
		if ('' === $this->signingSecret || 1 !== preg_match(self::SESSION_ID_PATTERN, $sessionId)) {
			return null;
		}

		$signature = rtrim(strtr(base64_encode(hash_hmac(
			'sha256',
			"iikiti-consent-session\0".(int) ($site->getId() ?? 0)."\0".$sessionId,
			$this->signingSecret,
			true,
		)), '+/', '-_'), '=');

		return $sessionId.'.'.$signature;
	}

	public function resolveSignedSessionId(Request $request, Site $site): ?string
	{
		if ('' === $this->signingSecret) {
			return null;
		}

		$cookie = $request->cookies->get(self::SESSION_COOKIE_NAME);
		if (!is_string($cookie) || '' === $cookie) {
			return null;
		}

		$separator = strrpos($cookie, '.');
		if (false === $separator) {
			return null;
		}

		$sessionId = substr($cookie, 0, $separator);
		if (1 !== preg_match(self::SESSION_ID_PATTERN, $sessionId)) {
			return null;
		}

		$expected = $this->signSessionId($sessionId, $site);

		return null !== $expected && hash_equals($expected, $cookie) ? $sessionId : null;
	}

	/**
	 * Return only browser-safe consent settings for the public banner runtime.
	 *
	 * @return array{version:int,default_categories:array<string,bool>}
	 */
	public function publicConfig(Site $site): array
	{
		$config = $this->config($site);

		return [
			'version' => (int) ($config[self::CONFIG_VERSION_KEY] ?? 1),
			'default_categories' => $this->normalizeCategories($config[self::CONFIG_DEFAULT_CATEGORIES_KEY] ?? self::DEFAULT_CATEGORIES),
		];
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
	 *
	 * @param array<string,bool>|null $before
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
