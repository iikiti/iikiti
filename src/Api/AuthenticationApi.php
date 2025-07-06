<?php

namespace iikiti\CMS\Api;

use iikiti\CMS\ApiResource\Authentication;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuthenticationApi
{
    private HttpClientInterface $client;

    public function __construct(private readonly AuthenticationUtils $authenticationUtils)
    {
    }

    public function getLastAuthenticationError(): ?\Exception
    {
        return $this->authenticationUtils->getLastAuthenticationError();
    }

    public function getLastUsername(): string
    {
        return $this->authenticationUtils->getLastUsername();
    }

    /**
     * Gets authentication information from the API
     *
     * @return Authentication
     */
    public function getAuthenticationInfo(): Authentication
    {
        // TODO: Implement through API
        $auth = new Authentication();
        $auth->error = $this->getLastAuthenticationError()?->getMessage();
        $auth->username = $this->getLastUsername();

        return $auth;
    }
}
