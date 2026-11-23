<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Pluggable response output filter invoked by {@see \iikiti\CMS\Event\Subscriber\OutputParser}
 * during the single-pass DOM serialization lifecycle.
 */
#[AutoconfigureTag('iikiti.cms.response_filter')]
interface ResponseFilterInterface
{
	public function filter(ResponseEvent $event): void;

	public function getPriority(): int;
}
