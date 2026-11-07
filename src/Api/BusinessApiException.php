<?php

declare(strict_types=1);

namespace iikiti\CMS\Api;

final class BusinessApiException extends \RuntimeException
{
	public function __construct(public readonly int $statusCode)
	{
		parent::__construct(sprintf('Business API returned HTTP %d.', $statusCode));
	}
}
