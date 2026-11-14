<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Firewall;

use iikiti\CMS\Repository\FirewallIpBanRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
	name: 'iikiti:firewall:status',
	description: 'Show active firewall operational bans.',
)]
final class FirewallStatusCommand extends Command
{
	private const PAGE_LIMIT = 100;

	public function __construct(
		private readonly FirewallIpBanRepository $bans,
		#[Autowire('%firewall_enabled%')]
		private readonly bool $firewallEnabled = true,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only show bans for this site ID (0 for global).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$io->writeln(sprintf(
			'Firewall status: %s',
			$this->firewallEnabled ? 'enabled' : 'disabled (FIREWALL_ENABLED=false)',
		));
		$siteOption = $input->getOption('site');
		$siteId = null;
		if (null !== $siteOption) {
			if (!is_string($siteOption) || !preg_match('/^(0|[1-9][0-9]{0,19})$/D', $siteOption)) {
				$io->error('The --site option must be a non-negative integer.');

				return Command::FAILURE;
			}
			$siteId = (int) $siteOption;
		}

		$now = new \DateTimeImmutable();
		$rows = [];
		foreach ($this->bans->findPaginated($siteId, 1, self::PAGE_LIMIT) as $ban) {
			if (!$ban->isActive($now)) {
				continue;
			}
			$expiresAt = $ban->getExpiresAt();
			$rows[] = [
				(string) $ban->getId(),
				(string) $ban->getSiteId(),
				$ban->getCidr(),
				$ban->getReasonCode(),
				(string) $ban->getStrikes(),
				null === $expiresAt ? 'permanent' : $expiresAt->format(\DateTimeInterface::ATOM),
			];
		}

		$io->title('Firewall operational bans');
		if ([] === $rows) {
			$io->writeln('No active bans.');

			return Command::SUCCESS;
		}
		$io->table(['ID', 'Site', 'CIDR', 'Reason', 'Strikes', 'Expires'], $rows);

		return Command::SUCCESS;
	}
}
