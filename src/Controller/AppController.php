<?php

namespace iikiti\CMS\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AppController
 */
#[AsController]
class AppController extends AbstractController {
    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/', name: "home", priority: 0)]
    function index(): Response
    {
        return $this->render(
            'index.twig',
            [
                'doc' => ['title' => 'iikiti']
            ]
        );
    }
}
