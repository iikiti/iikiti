<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\FirewallIpBanResource;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Creates an operator-initiated ban (global scope). Escalated auto-bans are created by the
 * request pipeline, not through this endpoint.
 *
 * @implements ProcessorInterface<FirewallIpBanResource,FirewallIpBanResource>
 */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallIpBanProcessor implements ProcessorInterface
{
	private const MAX_TTL_SECONDS = 31536000;

	public function __construct(
		private FirewallAutoBanManager $autoBans,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallIpBanResource
	{
		$ttl = $data->ttlSeconds;
		if (null !== $ttl && ($ttl < 1 || $ttl > self::MAX_TTL_SECONDS)) {
			throw new BadRequestHttpException(sprintf('ttlSeconds must be between 1 and %d.', self::MAX_TTL_SECONDS));
		}

		try {
			$ban = $this->autoBans->ban(
				$data->cidr,
				'operator:manual',
				'' === $data->reasonMessage ? 'Manually banned by an administrator.' : $data->reasonMessage,
				$ttl,
				0,
			);
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}

		$this->audit->record(
			sprintf('Banned %s', $ban->getCidr()),
			'firewall_ip_banned',
			FirewallIpBan::class,
			$ban->getId(),
			null,
			['cidr' => $ban->getCidr(), 'ttlSeconds' => $ttl],
		);

		$data->id = $ban->getId();
		$data->siteId = $ban->getSiteId();
		$data->cidr = $ban->getCidr();
		$data->reasonCode = $ban->getReasonCode();
		$data->strikes = $ban->getStrikes();
		$data->expiresAt = $ban->getExpiresAt()?->format(\DateTimeInterface::ATOM);
		$data->createdAt = $ban->getCreatedAt()->format(\DateTimeInterface::ATOM);

		return $data;
	}
}
