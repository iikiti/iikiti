<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

class DoctrineSchemaSubscriber implements EventSubscriber
{
    public function __construct(private string $schema)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [
            ToolEvents::postGenerateSchema,
        ];
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
                //$schema->renameTable($table->getName(), $this->schema . '.' . $table->getName());
            }
        }
    }
}