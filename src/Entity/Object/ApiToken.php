<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ApiTokenRepository;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken extends DbObject
{
	public const SITE_SPECIFIC = false;

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
        return $this->getProperties()->get('user');
    }

    public function setUser(?User $user): self
    {
		$this->setProperty('user', $user->getId());

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