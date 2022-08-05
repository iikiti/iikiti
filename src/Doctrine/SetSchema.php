<?php

namespace iikiti\CMS\Doctrine;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class SetSchema {

    public function __construct(protected readonly ContainerBagInterface $args)
    {
    }

    public function postConnect(ConnectionEventArgs $connArgs) {
        $conn = $connArgs->getConnection();
        $schema = filter_var(
            $_SERVER['DATABASE_SCHEMA'] ?? '',
            FILTER_CALLBACK,
            [
                'options' => function($val) {
                    return preg_match(
                        '/^(\p{L}\p{M}*(?:\p{L}\p{M}*|[_0-9$])*)?$/u',
                        $val
                    ) ? $val : false;
                }
            ]
        );
        if(!empty($schema)) {
            $conn->executeQuery(
                'SET search_path TO ' . $schema
            );
        }
    }

}
