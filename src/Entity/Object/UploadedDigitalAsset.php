<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\AbstractDigitalAsset;

#[ORM\Entity]
#[ORM\Table(name: 'object_lookup')]
class UploadedDigitalAsset extends AbstractDigitalAsset
{
	public function isUploaded(): bool
	{
		return true;
	}
}
