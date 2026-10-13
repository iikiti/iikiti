<?php

declare(strict_types=1);

namespace iikiti\CMS\Event;

final class DigitalAssetEvents
{
	public const CREATED = 'iikiti.digital_asset.created';
	public const UPDATED = 'iikiti.digital_asset.updated';
	public const DELETED = 'iikiti.digital_asset.deleted';
	public const PROCESSING_STARTED = 'iikiti.digital_asset.processing_started';
	public const PROCESSING_COMPLETED = 'iikiti.digital_asset.processing_completed';
	public const PROCESSING_FAILED = 'iikiti.digital_asset.processing_failed';
}
