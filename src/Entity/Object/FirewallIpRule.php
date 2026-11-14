<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleSpec;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * Managed IP/CIDR allow, block, or report rule. Site 0 denotes a global rule.
 *
 * Values live in object properties (not dedicated columns) so the rule shares the
 * generic object storage contract; they are read and written only via typed accessors.
 */
#[ORM\Entity(repositoryClass: FirewallIpRuleRepository::class)]
#[ORM\Table(name: 'object_lookup')]
class FirewallIpRule extends DbObject
{
	public const SITE_SPECIFIC = false;

	private const ACTIONS = ['allow', 'block', 'report'];
	private const INHERITANCES = ['default', 'required'];
	private const MAX_REASON_LENGTH = 512;

	public function getCidr(): string
	{
		return $this->readString('cidr');
	}

	public function setCidr(string $cidr): void
	{
		$normalized = IpCidrNormalizer::normalize($cidr);
		$this->setProperty('cidr', $normalized['cidr']);
		$this->setProperty('ip_version', $normalized['version']);
	}

	public function getIpVersion(): int
	{
		return (int) ($this->getPropertyValue('ip_version') ?? 4);
	}

	public function getAction(): string
	{
		return $this->readString('action', 'block');
	}

	public function setAction(string $action): void
	{
		$this->setProperty('action', $this->assertIn($action, self::ACTIONS, 'action'));
	}

	public function getInheritance(): string
	{
		return $this->readString('inheritance', 'default');
	}

	public function setInheritance(string $inheritance): void
	{
		$this->setProperty('inheritance', $this->assertIn($inheritance, self::INHERITANCES, 'inheritance'));
	}

	public function getReason(): string
	{
		return $this->readString('reason');
	}

	public function setReason(string $reason): void
	{
		$trimmed = trim($reason);
		if (strlen($trimmed) > self::MAX_REASON_LENGTH) {
			throw new \InvalidArgumentException('IP rule reason is too long.');
		}
		$this->setProperty('reason', $trimmed);
	}

	public function isEnabled(): bool
	{
		return (bool) ($this->getPropertyValue('enabled') ?? true);
	}

	public function setEnabled(bool $enabled): void
	{
		$this->setProperty('enabled', $enabled);
	}

	public function getExpiresAt(): ?\DateTimeImmutable
	{
		$timestamp = $this->getPropertyValue('expires_at');
		if (null === $timestamp) {
			return null;
		}

		return (new \DateTimeImmutable())->setTimestamp((int) $timestamp);
	}

	public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
	{
		if (null === $expiresAt) {
			$this->getProperties()->remove('expires_at');

			return;
		}
		$this->setProperty('expires_at', $expiresAt->getTimestamp());
	}

	public function getPriority(): int
	{
		return (int) ($this->getPropertyValue('priority') ?? 0);
	}

	public function setPriority(int $priority): void
	{
		$this->setProperty('priority', $priority);
	}

	public function toSpec(): FirewallIpRuleSpec
	{
		return new FirewallIpRuleSpec(
			id: 'ip-rule:'.(string) ($this->getId() ?? '0'),
			cidr: $this->getCidr(),
			action: $this->getAction(),
			inheritance: $this->getInheritance(),
			scope: '0' === (string) $this->getSiteId() ? 'global' : 'site',
			priority: $this->getPriority(),
			enabled: $this->isEnabled(),
			expiresAt: $this->getExpiresAt()?->getTimestamp(),
			reason: $this->getReason(),
		);
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
			throw new \InvalidArgumentException(sprintf('Unsupported IP rule %s "%s".', $field, $value));
		}

		return $value;
	}
}
