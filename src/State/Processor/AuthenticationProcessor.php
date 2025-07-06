<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\Authentication;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[AutoconfigureTag('api_platform.state_processor')]
readonly class AuthenticationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security            $security,
        private AuthenticationUtils $authenticationUtils
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Authentication
    {
        if (!$data instanceof Authentication) {
            throw new \InvalidArgumentException('Data is not an instance of Authentication');
        }

        // Get the last error if there was one
        $error = $this->authenticationUtils->getLastAuthenticationError();

        if ($error instanceof AuthenticationException) {
            $data->error = $error->getMessage();
            $data->success = false;
        } else {
            $data->success = $this->security->getUser() !== null;
        }

        return $data;
    }
}
