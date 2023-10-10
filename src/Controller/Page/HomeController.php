<?php

namespace iikiti\CMS\Controller\Page;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\UserRepository;
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
		/** @var UserRepository $uem */
		$uem = $em->getRepository(User::class);
		var_dump($uem->getClassName());
		var_dump($uem->find(2));
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
