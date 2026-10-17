<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\ApiTokenRepository;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken extends DbObject
{
	public const SITE_SPECIFIC = false;

	public function __construct()
	{
		$this->setProperties(new ArrayCollection());
	}

	#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
	private ?string $token = null;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'api_token_user_id', referencedColumnName: 'id', nullable: true)]
	private ?User $user = null;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $expiresAt = null;

	public function getToken(): string
	{
		return $this->token ?? throw new \LogicException('API token value is missing.');
	}

	public function setToken(string $token): void
	{
		$this->token = $token;
	}

	public function getUser(): User
	{
		return $this->user ?? throw new \LogicException('API token has no owner.');
	}

	public function setUser(User $user): void
	{
		$this->user = $user;
		$this->setCreatorId($user->getId() ?? 0);
	}

	public function getExpiresAt(): ?\DateTimeInterface
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(\DateTimeImmutable $expiresAt): void
	{
		$this->expiresAt = $expiresAt;
	}

	public function isExpired(): bool
	{
		return null === $this->expiresAt || $this->expiresAt < new \DateTimeImmutable();
	}
}
