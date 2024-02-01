<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\API\APIController;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ObjectController.
 */
#[AsController]
class ObjectController extends AppController
{
	#[Route('/{slug}', name: 'page', requirements: ['slug' => '[\w\d\_]+', 'page' => '\d+'], priority: 0)]
	public function page(Request $request, APIController $api): Response
	{
		$page = $api->getObjectsByContent(
			typeClass: Page::class,
			criteria: 'CONTAINS(JSON_GET_FIELD(o.content_json, \'slug\'), :slug) = true',
			options: [
				'parameters' => ['slug' => json_encode($request->attributes->get('slug'))],
				'orderBy' => ['o.created_date' => 'DESC'],
				'limit' => 1,
				'singleResult' => true,
			]
		);
		if (false == $page instanceof DbObject) {
			throw $this->createNotFoundException('Page does not exist.');
		}

		return $this->render(
			'object.twig',
			['doc' => ['title' => 'iikiti']]
		);
	}
}
