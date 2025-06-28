<?php

namespace iikiti\CMS\Entity;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ApiTokenRepository;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken extends DbObject
{
	public const SITE_SPECIFIC = false;
	public const PROPERTY_KEY = 'application';

	/*
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $token = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;
	*/

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): ?string
    {
        return $this->getProperties()->get('token');
    }

    public function setToken(string $token): self
    {
		$this->setProperty('token', $token);

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->getUser();
    }

    public function setUser(?User $user): self
    {
		$this->setUser($user);

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
		return $this->getProperties()->get('expiresAt');
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
		$this->setProperty('expiresAt', $expiresAt);

        return $this;
    }

    public function isExpired(): bool
    {
		$expiredAt = $this->getExpiresAt();
        return $expiredAt === null || $expiredAt < new \DateTimeImmutable();
    }
}