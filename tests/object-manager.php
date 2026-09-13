<?php

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Kernel;

require __DIR__.'/bootstrap.php';

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

/** @var EntityManagerInterface $objectManager */
$objectManager = $kernel->getContainer()->get('doctrine')->getManager();

return $objectManager;