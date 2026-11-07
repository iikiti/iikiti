<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;
use iikiti\CMS\Batch\PermanentBatchMessageFailure;

final readonly class FirewallRulePackRefreshHandler implements BatchMessageHandlerInterface
{
	public const TYPE = 'security.firewall.pack_refresh';

	public function __construct(private FirewallRulePackManager $packs)
	{
	}

	public function getType(): string
	{
		return self::TYPE;
	}

	public function handle(BatchMessage $message, BatchMessageContext $context): void
	{
		$source = $message->payload['source'] ?? null;
		if (!is_string($source)) {
			throw new PermanentBatchMessageFailure('Firewall pack refresh payload is invalid.');
		}
		try {
			$this->packs->refresh($source);
		} catch (FirewallRulePackRejected $exception) {
			throw new PermanentBatchMessageFailure('Signed firewall rule pack failed validation.', previous: $exception);
		}
		$context->report('Signed firewall rule pack was refreshed.', ['source' => $source]);
	}
}
