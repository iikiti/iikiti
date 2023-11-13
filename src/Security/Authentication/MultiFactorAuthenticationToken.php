<?php
namespace iikiti\CMS\Security\Authentication;

use InvalidArgumentException;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class MultiFactorAuthenticationToken extends AbstractToken implements MultiFactorTokenInterface {

	/** @var array<TokenInterface> */
	private array $tokenChain = [];

	private bool $authenticated = false;

	public function __construct(array $roles = []) {
		parent::__construct($roles);
	}

	public function getTokenChain(): array {
		return $this->tokenChain;
	}

	public function getLastToken(): ?TokenInterface {
		return $this->getTokenChain()[array_key_last($this->getTokenChain())] ?? null;
	}

	public function getUser(): ?UserInterface {
        return $this->getLastToken()?->getUser() ?? null;
    }

	public function setUser(UserInterface $user) {
        $this->getLastToken()?->setUser($user);
    }

	public function addToken(TokenInterface $token): void {
		if($token instanceof self) {
			throw new InvalidArgumentException('Cannot add token of type ' . self::class);
		}
		array_push($this->tokenChain, $token);
	}

	public function isAuthenticated(): bool {
		return $this->authenticated;
	}

	public function setIsAuthenticated(bool $authenticated): void {
		$this->authenticated = $authenticated;
	}

	public function getRoleNames(): array {
		return $this->getLastToken()?->getRoleNames() ?? [];
	}

	public function getUserIdentifier(): string {
		return $this->getUser()?->getUserIdentifier() ?? '';
	}

	public function getAttributes(): array {
		return $this->getLastToken()?->getAttributes() ?? [];
	}

	public function setAttributes(array $attributes) {
		$this->getLastToken()?->setAttributes($attributes);
	}

	public function hasAttribute(string $name): bool {
		return $this->getLastToken()?->hasAttribute($name) ?? false;
	}

	public function getAttribute(string $name): mixed {
		return $this->getLastToken()?->getAttribute($name);
	}

	public function setAttribute(string $name, mixed $value) {
		$this->getLastToken()?->setAttribute($name, $value);
	}

	public function __serialize(): array {
		$data = parent::__serialize();
		$data[] = $this->tokenChain;
		return $data;
	}

	public function __unserialize(array $data): void {
		[, , , , , $this->tokenChain] = $data;
		parent::__unserialize($data);
	}

}

