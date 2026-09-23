<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;

use function is_subclass_of;
use function strtolower;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
class DynamicDiscriminatorMapListener
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if (! $metadata instanceof ClassMetadata) {
            return;
        }

        if ($metadata->getName() !== DbObject::class) {
            return;
        }

        if (! $metadata->isRootEntity()) {
            return;
        }

        $this->buildDiscriminatorMap($metadata);
    }

    private function buildDiscriminatorMap(ClassMetadata $metadata): void
    {
        $em = $this->registry->getManager();
        $driver = $em->getConfiguration()->getMetadataDriverImpl();

        if ($driver === null) {
            return;
        }

        $baseFqcn = $metadata->getName();
        $map      = [strtolower($metadata->getReflectionClass()->getShortName()) => $baseFqcn];

        foreach ($driver->getAllClassNames() as $candidate) {
            if (! is_subclass_of($candidate, $baseFqcn)) {
                continue;
            }

            $shortName = strtolower((new \ReflectionClass($candidate))->getShortName());

            if (isset($map[$shortName])) {
                continue;
            }

            $map[$shortName] = $candidate;
        }

        $metadata->setDiscriminatorMap($map);
    }
}
