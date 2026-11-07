<?php

namespace iikiti\CMS\ApiResource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class Authentication
{
	public function __construct(
		#[Assert\NotBlank(message: 'Username cannot be blank')]
		#[Groups(['authentication:read', 'authentication:write'])]
		public string $username = '',

		#[Assert\NotBlank(message: 'Password cannot be blank')]
		#[Groups(['authentication:write'])]
		public string $password = ''
	) {
	}

	#[Groups(['authentication:read'])]
	public ?string $error = null;
	#[Groups(['authentication:read'])]
	public bool $success = false;
}
