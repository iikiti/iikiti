<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Firewall;

use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
	name: 'iikiti:firewall:unban',
	description: 'Remove operational bans for an IP address or CIDR range.',
)]
final class FirewallUnbanCommand extends Command
{
	public function __construct(private readonly FirewallAutoBanManager $bans)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addArgument('ip-or-cidr', InputArgument::REQUIRED, 'IPv4/IPv6 address or CIDR range to unban.')
			->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only remove bans in this site scope (0 for global). Omit to remove from all scopes.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			IpCidrNormalizer::normalize((string) $input->getArgument('ip-or-cidr'));
			$siteId = $this->siteOption($input);
		} catch (\InvalidArgumentException $exception) {
			$output->writeln('<error>'.$exception->getMessage().'</error>');

			return Command::FAILURE;
		}

		$removed = $this->bans->unbanByIpOrCidr((string) $input->getArgument('ip-or-cidr'), $siteId);
		if (0 === $removed) {
			$output->writeln('No matching bans found.');

			return Command::SUCCESS;
		}
		$output->writeln(sprintf('Removed %d ban(s).', $removed));

		return Command::SUCCESS;
	}

	private function siteOption(InputInterface $input): ?int
	{
		$raw = $input->getOption('site');
		if (null === $raw) {
			return null;
		}
		if (!is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,19})$/D', $raw)) {
			throw new \InvalidArgumentException('The --site option must be a non-negative integer.');
		}

		return (int) $raw;
	}
}
