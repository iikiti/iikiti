<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * Operator-defined trusted automation (webhook, CI, approved AI/MCP agent).
 *
 * Secrets are never exposed through getters that serialize: callers read the
 * encrypted/hashed material only via {@see getSecretMaterial()} inside verification.
 */
#[ORM\Entity(repositoryClass: FirewallTrustedAutomationRepository::class)]
#[ORM\Table(name: 'object_lookup')]
class FirewallTrustedAutomation extends DbObject
{
	public const SITE_SPECIFIC = false;

	public const CATEGORIES = ['custom_automation', 'ai_agent', 'monitoring', 'webhook'];
	public const AUTH_MODES = ['cidr', 'hmac', 'header_secret', 'http_signature'];
	private const HANDLE_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';
	private const MAX_HEADER_NAME_LENGTH = 64;

	public function getName(): string
	{
		return $this->readString('name');
	}

	public function setName(string $name): void
	{
		$trimmed = trim($name);
		if ('' === $trimmed || strlen($trimmed) > 128) {
			throw new \InvalidArgumentException('Trusted automation name is empty or too long.');
		}
		$this->setProperty('name', $trimmed);
	}

	public function getHandle(): string
	{
		return $this->readString('handle');
	}

	public function setHandle(string $handle): void
	{
		if (1 !== preg_match(self::HANDLE_PATTERN, $handle)) {
			throw new \InvalidArgumentException('Trusted automation handle is invalid.');
		}
		$this->setProperty('handle', $handle);
	}

	public function getCategory(): string
	{
		return $this->readString('category', 'custom_automation');
	}

	public function setCategory(string $category): void
	{
		$this->setProperty('category', $this->assertIn($category, self::CATEGORIES, 'category'));
	}

	public function getAuthMode(): string
	{
		return $this->readString('auth_mode', 'cidr');
	}

	public function setAuthMode(string $authMode): void
	{
		$this->setProperty('auth_mode', $this->assertIn($authMode, self::AUTH_MODES, 'auth mode'));
	}

	/** @return list<string> */
	public function getCidrs(): array
	{
		$value = $this->getPropertyValue('cidrs');

		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}

	/** @param list<string> $cidrs */
	public function setCidrs(array $cidrs): void
	{
		$normalized = [];
		foreach ($cidrs as $cidr) {
			$normalized[] = IpCidrNormalizer::normalize($cidr)['cidr'];
		}
		$this->setProperty('cidrs', array_values(array_unique($normalized)));
	}

	public function getHeaderName(): string
	{
		return $this->readString('header_name');
	}

	public function setHeaderName(string $headerName): void
	{
		$trimmed = trim($headerName);
		if (1 !== preg_match('/^[A-Za-z0-9-]{1,'.self::MAX_HEADER_NAME_LENGTH.'}$/D', $trimmed)) {
			throw new \InvalidArgumentException('Trusted automation header name is invalid.');
		}
		$this->setProperty('header_name', $trimmed);
	}

	/**
	 * Returns the stored secret material: a SHA-256 hash for header secrets or the
	 * encrypted HMAC secret. Never returned to API consumers.
	 */
	public function getSecretMaterial(): ?string
	{
		$value = $this->getPropertyValue('secret_material');

		return is_string($value) ? $value : null;
	}

	public function setSecretMaterial(?string $material): void
	{
		if (null === $material) {
			$this->getProperties()->remove('secret_material');

			return;
		}
		$this->setProperty('secret_material', $material);
	}

	public function getPublicKey(): ?string
	{
		$value = $this->getPropertyValue('public_key');

		return is_string($value) ? $value : null;
	}

	public function setPublicKey(?string $publicKey): void
	{
		if (null === $publicKey) {
			$this->getProperties()->remove('public_key');

			return;
		}
		$this->setProperty('public_key', $publicKey);
	}

	/** @return list<string> */
	public function getAllowedPaths(): array
	{
		$value = $this->getPropertyValue('allowed_paths');

		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}

	/** @param list<string> $paths */
	public function setAllowedPaths(array $paths): void
	{
		$clean = array_values(array_unique(array_filter(array_map('trim', $paths), static fn (string $path): bool => str_starts_with($path, '/'))));
		$this->setProperty('allowed_paths', $clean);
	}

	public function isBypassWaf(): bool
	{
		return (bool) ($this->getPropertyValue('bypass_waf') ?? false);
	}

	public function setBypassWaf(bool $bypassWaf): void
	{
		$this->setProperty('bypass_waf', $bypassWaf);
	}

	/** @return array{limit:int,interval:int}|null */
	public function getRateLimitOverride(): ?array
	{
		$value = $this->getPropertyValue('rate_limit_override');
		if (!is_array($value) || !is_int($value['limit'] ?? null) || !is_int($value['interval'] ?? null)) {
			return null;
		}

		return ['limit' => $value['limit'], 'interval' => $value['interval']];
	}

	/** @param array{limit:int,interval:int}|null $override */
	public function setRateLimitOverride(?array $override): void
	{
		if (null === $override) {
			$this->getProperties()->remove('rate_limit_override');

			return;
		}
		if ($override['limit'] < 1 || $override['interval'] < 1) {
			throw new \InvalidArgumentException('Rate limit override must use positive limit and interval.');
		}
		$this->setProperty('rate_limit_override', $override);
	}

	public function isEnabled(): bool
	{
		return (bool) ($this->getPropertyValue('enabled') ?? true);
	}

	public function setEnabled(bool $enabled): void
	{
		$this->setProperty('enabled', $enabled);
	}

	public function getPriority(): int
	{
		return (int) ($this->getPropertyValue('priority') ?? 0);
	}

	public function setPriority(int $priority): void
	{
		$this->setProperty('priority', $priority);
	}

	private function getPropertyValue(string $name): mixed
	{
		$property = $this->getProperties()->get($name);

		return null === $property ? null : $property->getValue();
	}

	private function readString(string $name, string $default = ''): string
	{
		$value = $this->getPropertyValue($name);

		return is_string($value) ? $value : $default;
	}

	/** @param list<string> $allowed */
	private function assertIn(string $value, array $allowed, string $field): string
	{
		if (!in_array($value, $allowed, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported trusted automation %s "%s".', $field, $value));
		}

		return $value;
	}
}
