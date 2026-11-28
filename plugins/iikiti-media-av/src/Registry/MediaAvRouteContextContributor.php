<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Registry;

use iikiti\CMS\Registry\AbstractRouteContextContributor;

/**
 * Classifies HLS delivery routes for database-free Consumer proxying.
 */
final class MediaAvRouteContextContributor extends AbstractRouteContextContributor
{
	public function getConsumerProxyRoutes(): array
	{
		return [
			'media_av_hls_delivery',
		];
	}
}
