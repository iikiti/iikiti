<?php

namespace iikiti\CMS\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use ReflectionClass;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
class DoctrineEventListener {
	private ?MappingDriver $driver;
	private $map;

	public function __construct(EntityManagerInterface $em) {
		$this->driver = $em->getConfiguration()->getMetadataDriverImpl();
	}

	/**
	 * Gets the class metadata of doctrine entities when loaded and generates
	 * 		a discriminator map using values associated with subclasses.
	 */
	public function loadClassMetadata(LoadClassMetadataEventArgs $args): void {
		if(!$this->isValidSuperclass($args->getClassMetadata()->getReflectionClass())) {
			return;
		}
		foreach($args->getClassMetadata()->subClasses as $subClass) {
			$subRef = new ReflectionClass($subClass);
			foreach($subRef->getAttributes() as $attr) {
				if($attr->getName() != DiscriminatorValue::class) {
					continue;
				}
				if(
					isset($attr->getArguments()[0]) &&
					!isset($args->getClassMetadata()->discriminatorMap[$attr->getArguments()[0]])
				) {
					$args->getClassMetadata()->addDiscriminatorMapClass(
						$attr->getArguments()[0],
						$subRef->getName()
					);
				}
			}
		}
	}

	protected function isValidSuperclass(ReflectionClass $reflect) {
		$isMappedSuperclass = false;
		$isSingleTableInh = false;
		foreach($reflect->getAttributes() as $attr) {
			if(!$isMappedSuperclass && $attr->getName() == MappedSuperclass::class) {
				$isMappedSuperclass = true;
			}
			if(
				!$isSingleTableInh &&
				$attr->getName() == InheritanceType::class &&
				$attr->getArguments()[0] == 'SINGLE_TABLE'
			) {
				$isSingleTableInh = true;
			}
		}
		return $isMappedSuperclass && $isSingleTableInh;
	}

}
