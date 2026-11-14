<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<mixed,null> */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallIpBanDeleteProcessor implements ProcessorInterface
{
	public function __construct(
		private FirewallIpBanRepository $bans,
		private FirewallAutoBanManager $autoBans,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
	{
		// Site routes may only remove bans in the request-host site's scope, never another site's.
		$siteScope = array_key_exists('siteId', $uriVariables)
			? (string) FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: '0';

		$ban = $this->bans->find($uriVariables['id'] ?? '');
		if (!$ban instanceof FirewallIpBan || (string) $ban->getSiteId() !== $siteScope) {
			throw new NotFoundHttpException('Firewall ban not found.');
		}

		$cidr = $ban->getCidr();
		$banId = $ban->getId();
		$this->autoBans->unban($banId);
		$this->audit->record(
			sprintf('Unbanned %s', $cidr),
			'firewall_ip_unbanned',
			FirewallIpBan::class,
			$banId,
		);

		return null;
	}
}
