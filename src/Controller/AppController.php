<?php

namespace iikiti\CMS\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Class AppController
 */
/** @psalm-suppress PropertyNotSetInConstructor */
abstract class AppController extends AbstractController {

	public function __construct(protected Security $security) {

	}

    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        return parent::setContainer($container);
    }
    
}
