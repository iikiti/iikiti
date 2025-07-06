<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\Authentication;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[AutoconfigureTag('api_platform.state_provider')]
readonly class AuthenticationProvider implements ProviderInterface
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils
    ) {
    }

    #[\Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $authentication = new Authentication();

        // Set the last username if available
        $lastUsername = $this->authenticationUtils->getLastUsername();
        if ($lastUsername) {
            $authentication->username = $lastUsername;
        }

        return $authentication;
    }
}
