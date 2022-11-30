<?php

namespace iikiti\CMS\Controller\Page;

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
