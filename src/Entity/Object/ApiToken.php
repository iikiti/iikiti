<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\Object\ApiTokenRepository;

/**
 * API token entity.
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
	#[ORM\Id()]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id;

	#[ORM\Column(type: Types::STRING, length: 255, unique: true)]
	private string $token = '';

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
	private User $user;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $expiresAt;

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function setToken(string $token): void
	{
		$this->token = $token;
	}

	public function getUser(): User
	{
		return $this->user;
	}

	public function setUser(User $user): void
	{
		$this->user = $user;
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
		return $this->expiresAt < new \DateTimeImmutable();
	}
}