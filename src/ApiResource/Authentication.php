<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\State\Processor\AuthenticationProcessor;
use iikiti\CMS\State\Provider\AuthenticationProvider;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource]
#[Post(
    uriTemplate: '/auth/login',
    openapi: [
        'summary' => 'Login to the application',
        'description' => 'Handles user authentication'
    ],
    security: 'is_granted("PUBLIC_ACCESS")',
    name: 'app_api_login',
    provider: AuthenticationProvider::class,
    processor: AuthenticationProcessor::class
)]
class Authentication
{
    public function __construct(
        #[Assert\NotBlank(message: 'Username cannot be blank')]
        public string $username = '',

        #[Assert\NotBlank(message: 'Password cannot be blank')]
        public string $password = ''
    ) {
    }

    public ?string $error = null;
    public bool $success = false;
}
