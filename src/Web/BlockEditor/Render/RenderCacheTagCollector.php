<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/** Collects explicit non-CMS dependencies declared by a widget template/plugin. */
final class RenderCacheTagCollector
{
	/** @var array<string,true> */
	private array $tags = [];

	public function add(string $tag): void
	{
		$tag = trim($tag);
		if ('' === $tag || strlen($tag) > 128 || 1 !== preg_match('/^[a-zA-Z0-9_.-]+$/D', $tag)) {
			return;
		}

		$this->tags[$tag] = true;
	}

	/** @return list<string> */
	public function all(): array
	{
		return array_keys($this->tags);
	}
}
