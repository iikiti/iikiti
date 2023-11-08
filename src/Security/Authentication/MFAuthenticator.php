<?php
namespace iikiti\CMS\Security\Authentication;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Workflow\Workflow;

class MFAuthenticator extends AbstractAuthenticator
{

    public function __construct(private Workflow $workflow) {
        
    }

    public function supports(Request $request): ?bool {
        return true;
    }

    public function authenticate(Request $request): Passport {
        // Get the user's credentials from the request.
        $username = (string) $request->request->get('username');
        $password = (string) $request->request->get('password');
		$credentials = new PasswordCredentials($password);

        // Authenticate the user using their username and password.
        if (!$this->isValidCredentials($username, $password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        // Transition the state machine to the `Request MFA Code` state.
        $this->workflow->transition('Request MFA Code');

        return new Passport(new UserBadge($username), $credentials);
    }

    public function onAuthenticationSuccess(
		Request $request,
		TokenInterface $token,
		string $firewallName
	): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(
		Request $request,
		AuthenticationException $exception
	): ?Response
	{
        return null;
    }

    private function isValidCredentials(string $username, string $password): bool {
        // TODO: Implement this method to check if the user's credentials are valid.
        return true;
    }

}

