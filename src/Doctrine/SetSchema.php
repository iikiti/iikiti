<?php

namespace iikiti\CMS\Doctrine;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class SetSchema {

    public function __construct(protected ContainerBagInterface $args)
    {
    }
    
    /**
     * postConnect
     * 
     * Doctrine event subscriber that checks for the presence of
     * DATABASE_SCHEMA in the server environement variables and filters it for
     * valid characters to prevent SQL injection attacks. If valid, will set
     * the search_path as the connection's default schema. This environment
     * variable can optionally be removed and set on a per-user basis.
     *
     * @param  ConnectionEventArgs $connArgs
     */
    public function postConnect(ConnectionEventArgs $connArgs): void {
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
