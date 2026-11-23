<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed;

/**
 * Contract for resolving a media or social URL into safe embed HTML.
 */
interface EmbedResolverInterface
{
	/**
	 * @param array<string,mixed> $options
	 */
	public function resolve(string $url, array $options = []): string;
}
