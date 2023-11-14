<?php
namespace iikiti\CMS\Security\Authentication;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

interface MultiFactorTokenInterface extends TokenInterface {

	public function getAssociatedToken(): ?TokenInterface;

	public function setAssociatedToken(TokenInterface $token): void;

	public function isAuthenticated(): bool;

	public function setIsAuthenticated(bool $authenticated): void;

}

