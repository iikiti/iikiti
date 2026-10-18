<?php

declare(strict_types=1);

namespace iikiti\CMS\Message;

final readonly class ProcessBatchItem
{
	public function __construct(public int|string $itemId)
	{
	}
}
