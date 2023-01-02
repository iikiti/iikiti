<?php

namespace iikiti\CMS\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Container\ContainerInterface;

/**
 * Class AppController
 */
abstract class AppController extends AbstractController {

    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        return parent::setContainer($container);
    }
    
}
