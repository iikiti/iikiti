<?php
namespace iikiti\CMS\Security\Authentication;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

interface MultiFactorTokenInterface extends TokenInterface {

	/**
	 * @return array<TokenInterface>
	 */
 	public function getTokenChain(): array;

	public function getLastToken(): ?TokenInterface;

	public function addToken(TokenInterface $token): void;

	public function isAuthenticated(): bool;

	public function setIsAuthenticated(bool $authenticated): void;

}

