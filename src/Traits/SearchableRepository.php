<?php

namespace iikiti\CMS\Traits;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Exception;

trait SearchableRespository {

    function __construct() {
        if(!($this instanceof ServiceEntityRepository)) {
            throw new Exception(
                'Incorrect type. Must be attached to an ' .
                ServiceEntityRepository::class
            );
        }
    }

}