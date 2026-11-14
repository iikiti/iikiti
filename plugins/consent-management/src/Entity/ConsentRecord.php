<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Entity;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\Extension\ConsentManagement\Repository\ConsentRecordRepository;

/**
 * A consent record stored per site + user or session.
 *
 * All consent data lives in `object_properties` under namespaced keys
 * (`privacy.consent.*`); no subtype columns are added to the shared
 * `objects` table.
 */
#[ORM\Entity(repositoryClass: ConsentRecordRepository::class)]
#[ORM\Table(name: 'object_lookup')]
class ConsentRecord extends DbObject
{
	public const TYPE = 'consentrecord';

	public const PROP_USER_ID = 'privacy.consent.user_id';
	public const PROP_SESSION_ID = 'privacy.consent.session_id';
	public const PROP_CATEGORIES = 'privacy.consent.categories';
	public const PROP_VERSION = 'privacy.consent.version';
	public const PROP_LEGAL_FRAMEWORK = 'privacy.consent.legal_framework';
	public const PROP_GEO_COUNTRY = 'privacy.consent.geo_country';
	public const PROP_SIGNALS = 'privacy.consent.signals';
	public const PROP_PROOF_TOKEN = 'privacy.consent.proof_token';
	public const PROP_CONSENTED_AT = 'privacy.consent.consented_at';
	public const PROP_IP_ADDRESS = 'privacy.consent.ip_address';
	public const PROP_USER_AGENT = 'privacy.consent.user_agent';

	/** @return array{necessary: bool, analytics: bool, marketing: bool} */
	public function getCategories(): array
	{
		$value = $this->getProperties()->get(self::PROP_CATEGORIES)?->getValue();
		if (!is_array($value)) {
			return ['necessary' => true, 'analytics' => false, 'marketing' => false];
		}

		return [
			'necessary' => (bool) ($value['necessary'] ?? true),
			'analytics' => (bool) ($value['analytics'] ?? false),
			'marketing' => (bool) ($value['marketing'] ?? false),
		];
	}

	public function setCategories(array $categories): void
	{
		$this->setProperty(self::PROP_CATEGORIES, [
			'necessary' => (bool) ($categories['necessary'] ?? true),
			'analytics' => (bool) ($categories['analytics'] ?? false),
			'marketing' => (bool) ($categories['marketing'] ?? false),
		]);
	}

	public function getVersion(): int
	{
		$value = $this->getProperties()->get(self::PROP_VERSION)?->getValue();
		return is_int($value) ? $value : 0;
	}

	public function setVersion(int $version): void
	{
		$this->setProperty(self::PROP_VERSION, $version);
	}

	public function getLegalFramework(): ?string
	{
		return $this->getProperties()->get(self::PROP_LEGAL_FRAMEWORK)?->getValue() ?? null;
	}

	public function setLegalFramework(?string $framework): void
	{
		$this->setProperty(self::PROP_LEGAL_FRAMEWORK, $framework);
	}

	public function getGeoCountry(): ?string
	{
		return $this->getProperties()->get(self::PROP_GEO_COUNTRY)?->getValue() ?? null;
	}

	public function setGeoCountry(?string $country): void
	{
		$this->setProperty(self::PROP_GEO_COUNTRY, $country);
	}

	/** @return array{ gpc?: bool, dnt?: bool }|null */
	public function getSignals(): ?array
	{
		$value = $this->getProperties()->get(self::PROP_SIGNALS)?->getValue();
		return is_array($value) ? $value : null;
	}

	public function setSignals(?array $signals): void
	{
		$this->setProperty(self::PROP_SIGNALS, $signals);
	}

	public function getProofToken(): ?string
	{
		return $this->getProperties()->get(self::PROP_PROOF_TOKEN)?->getValue() ?? null;
	}

	public function setProofToken(string $token): void
	{
		$this->setProperty(self::PROP_PROOF_TOKEN, $token);
	}

	public function getConsentedAt(): ?\DateTimeImmutable
	{
		$value = $this->getProperties()->get(self::PROP_CONSENTED_AT)?->getValue();
		if (null === $value) {
			return null;
		}

		if ($value instanceof \DateTimeImmutable) {
			return $value;
		}

		if (is_string($value)) {
			return new \DateTimeImmutable($value);
		}

		return null;
	}

	public function setConsentedAt(?\DateTimeImmutable $dateTime): void
	{
		$this->setProperty(self::PROP_CONSENTED_AT, $dateTime?->format(\DateTimeInterface::ATOM));
	}

	public function getIpAddress(): ?string
	{
		return $this->getProperties()->get(self::PROP_IP_ADDRESS)?->getValue() ?? null;
	}

	public function setIpAddress(?string $ip): void
	{
		$this->setProperty(self::PROP_IP_ADDRESS, $ip);
	}

	public function getUserAgent(): ?string
	{
		return $this->getProperties()->get(self::PROP_USER_AGENT)?->getValue() ?? null;
	}

	public function setUserAgent(?string $ua): void
	{
		$this->setProperty(self::PROP_USER_AGENT, $ua);
	}

	public function getUserId(): int|string|null
	{
		return $this->getProperties()->get(self::PROP_USER_ID)?->getValue() ?? null;
	}

	public function setUserId(int|string|null $userId): void
	{
		$this->setProperty(self::PROP_USER_ID, $userId);
	}

	public function getSessionId(): ?string
	{
		return $this->getProperties()->get(self::PROP_SESSION_ID)?->getValue() ?? null;
	}

	public function setSessionId(?string $sessionId): void
	{
		$this->setProperty(self::PROP_SESSION_ID, $sessionId);
	}

	/**
	 * Proof-of-consent snapshot returned to callers.
	 *
	 * @return array<string,mixed>
	 */
	public function toProofArray(): array
	{
		return [
			'proof_token' => $this->getProofToken(),
			'consented_at' => $this->getConsentedAt()?->format(\DateTimeInterface::ATOM),
			'legal_framework' => $this->getLegalFramework(),
			'geo_country' => $this->getGeoCountry(),
			'signals' => $this->getSignals(),
			'ip_address' => $this->getIpAddress(),
			'user_agent' => $this->getUserAgent(),
			'categories' => $this->getCategories(),
			'version' => $this->getVersion(),
			'user_id' => $this->getUserId(),
			'session_id' => $this->getSessionId(),
		];
	}
}
