<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/** Mutable callback state used to distinguish cache failures from render errors. */
final class RenderedFragmentCacheAttempt
{
	/** @var array{html:string,styles:list<array{id:?string,css:string}>}|null */
	public ?array $result = null;

	public ?\Throwable $renderFailure = null;
}
