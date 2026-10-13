<?php

declare(strict_types=1);

namespace iikiti\CMS\Message;

final class ProcessDigitalAsset
{
	public function __construct(public readonly int|string $assetId)
	{
	}
}
