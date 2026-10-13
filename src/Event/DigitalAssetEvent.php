<?php

declare(strict_types=1);

namespace iikiti\CMS\Event;

use iikiti\CMS\Entity\AbstractDigitalAsset;

final class DigitalAssetEvent
{
	public function __construct(private readonly AbstractDigitalAsset $asset)
	{
	}

	public function getAsset(): AbstractDigitalAsset
	{
		return $this->asset;
	}
}
