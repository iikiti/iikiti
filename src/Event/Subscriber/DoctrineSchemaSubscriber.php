<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;


#[AsDoctrineListener(ToolEvents::postGenerateSchema)]
class DoctrineSchemaSubscriber
{
    public function __construct(
        #[Autowire('%env(DB_SCHEMA)%')]
        private string $schema
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $entityManager = $args->getEntityManager();
        $allMetadata = $entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            if ($metadata->isMappedSuperclass) {
                continue;
            }

            $tableName = $metadata->getTableName();
            if ($schema->hasTable($tableName)) {
                $table = $schema->getTable($tableName);
                $schema->renameTable($table->getName(), $this->schema . '.' . $table->getName());
            }
        }
    }
}