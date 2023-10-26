<?php

namespace iikiti\CMS\Controller\Page;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AppController
 */
#[AsController]
class HomeController extends AppController {

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/', name: "home", priority: 0)]
    function index(EntityManagerInterface $em): Response
    {
        return $this->render(
            'index.twig',
            [
                'doc' => ['title' => 'iikiti']
            ],
            new Response(
                '',
                Response::HTTP_OK,
                ['content-type' => 'text/html']
            )
        );
    }

}
