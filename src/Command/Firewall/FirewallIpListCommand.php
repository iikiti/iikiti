<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Firewall;

use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:firewall:ip-list',
	description: 'List managed IP/CIDR firewall rules.',
)]
final class FirewallIpListCommand extends Command
{
	public function __construct(private readonly FirewallIpRuleRepository $rules)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only list rules for this site ID (0 for global).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$siteOption = $input->getOption('site');

		try {
			$rules = null === $siteOption
				? $this->rules->findBy([], null, options: ['filterBySite' => false])
				: $this->rules->findForScope(FirewallIpRuleCommandSupport::parseSiteId($input));
		} catch (\InvalidArgumentException $exception) {
			$io->error($exception->getMessage());

			return Command::FAILURE;
		}

		$rows = [];
		foreach ($rules as $rule) {
			$expiresAt = $rule->getExpiresAt();
			$rows[] = [
				(string) $rule->getId(),
				(string) $rule->getSiteId(),
				$rule->getCidr(),
				$rule->getAction(),
				$rule->getInheritance(),
				$rule->isEnabled() ? 'yes' : 'no',
				(string) $rule->getPriority(),
				null === $expiresAt ? '-' : $expiresAt->format(\DateTimeInterface::ATOM),
				$rule->getReason(),
			];
		}

		$io->table(['ID', 'Site', 'CIDR', 'Action', 'Inheritance', 'Enabled', 'Priority', 'Expires', 'Reason'], $rows);

		return Command::SUCCESS;
	}
}
