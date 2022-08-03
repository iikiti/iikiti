<?php

namespace iikiti\CMS\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class TestController
 *
 * @package iikiti\CMSBundle\Controller
 */
#[AsController]
class TestController extends AppController {
    #[Route('/test', name: "test", priority: 0)]
    function index(): Response
    {
        $response = new Response(
            '',
            Response::HTTP_OK,
            ['content-type' => 'text/html']
        );
        return $this->render(
            'index.twig',
            ['doc' => ['title' => 'iikiti']], $response
        );
    }
}
