<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
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
    function html_login(AuthenticationUtils $authenticationUtils): Response
    {
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
                'doc' => ['title' => 'iikiti'],
                'last_username' => $lastUsername,
                'error'         => $error,
            ],
            $response
        );
    }

    #[Route('/logout', name: "html_logout")]
    public function logout()
    {
        return;
    }

}
