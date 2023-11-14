<?php
namespace iikiti\CMS\Security\Authentication;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class MultiFactorAuthenticationToken extends AbstractToken implements MultiFactorTokenInterface {

	/** @psalm-suppress PropertyNotSetInConstructor */
	private TokenInterface $associatedToken;

	private bool $authenticated = false;

	public function __construct(array $roles = []) {
		parent::__construct($roles);
	}

	public function getAssociatedToken(): TokenInterface {
		return $this->associatedToken;
	}

	public function setAssociatedToken(TokenInterface $token): void {
		$this->associatedToken = $token;
	}

	public function getUser(): ?UserInterface {
        return $this->getAssociatedToken()->getUser();
    }

	public function setUser(UserInterface $user) {
        $this->getAssociatedToken()->setUser($user);
    }

	public function isAuthenticated(): bool {
		return $this->authenticated;
	}

	public function setIsAuthenticated(bool $authenticated): void {
		$this->authenticated = $authenticated;
	}

	public function getRoleNames(): array {
		return $this->getAssociatedToken()->getRoleNames();
	}

	public function getUserIdentifier(): string {
		return $this->getUser()?->getUserIdentifier() ?? '';
	}

	public function getAttributes(): array {
		return $this->getAssociatedToken()->getAttributes();
	}

	public function setAttributes(array $attributes) {
		$this->getAssociatedToken()->setAttributes($attributes);
	}

	public function hasAttribute(string $name): bool {
		return $this->getAssociatedToken()->hasAttribute($name);
	}

	public function getAttribute(string $name): mixed {
		return $this->getAssociatedToken()->getAttribute($name);
	}

	public function setAttribute(string $name, mixed $value) {
		$this->getAssociatedToken()->setAttribute($name, $value);
	}

	public function __serialize(): array {
		$data = parent::__serialize();
		$data[] = $this->associatedToken;
		return $data;
	}

	public function __unserialize(array $data): void {
		[, , , , , $this->associatedToken] = $data;
		parent::__unserialize($data);
	}

}

