<?php

namespace iikiti\CMS\Controller;

use iikiti\CMS\Controller\API\APIController;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ObjectController
 *
 * @package iikiti\CMSBundle\Controller
 */
#[AsController]
class ObjectController extends AppController {
    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/{slug}', name: 'page', requirements: ['slug' => '[\w\d\_]+', 'page' => '\d+'], priority: 0)]
    function page(Request $request, APIController $api): Response
    {
        $page = $api->getObjectsByMeta(
            typeClass: Page::class,
            criteria: 'CONTAINS(meta.json_content->slug, :slug) = true',
            options: [
                'parameters' => ['slug' => $request->get('slug')],
                'orderBy' => ['meta.created_date_utc' => 'DESC'],
                'limit' => 1,
                'singleResult' => true
            ]
        );
        if($page instanceof DbObject == false) {
            throw $this->createNotFoundException('Page does not exist.');
        }
        $response = new Response(
            '',
            Response::HTTP_OK,
            ['content-type' => 'text/html']
        );
        return $this->render(
            'object.twig',
            ['doc' => ['title' => 'iikiti']], $response
        );
    }
}
