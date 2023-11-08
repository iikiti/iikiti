<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Class AuthenticationController
 *
 * @package iikiti\CMSBundle\Controller
 */
#[AsController]
class AuthenticationController extends AppController
{
    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/login', name: "html_login")]
    function html_login(
		AuthenticationUtils $authenticationUtils,
		AuthorizationCheckerInterface $authChecker
	): Response {
		if($authChecker->isGranted('IS_MFA_IN_PROGRESS')) {
			dump('MFA in progress');
		}
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        $response = new Response(
            '',
            Response::HTTP_OK,
            ['content-type' => 'text/html']
        );

        return $this->render(
            'login.twig',
            [
                'doc' => ['title' => 'iikiti login'],
                'last_username' => $lastUsername,
                'error'         => $error,
            ],
            $response
        );
    }

    #[Route('/logout', name: "html_logout")]
    public function logout(): void
    {
        return;
    }

}
