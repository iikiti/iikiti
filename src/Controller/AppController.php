<?php

namespace iikiti\CMS\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Class AppController.
 */
abstract class AppController extends AbstractController
{
	public function __construct(protected Security $security, ContainerInterface $container)
	{
		$this->container = $container;
	}

	public function setContainer(ContainerInterface $container): ?ContainerInterface
	{
		return parent::setContainer($container);
	}
}
