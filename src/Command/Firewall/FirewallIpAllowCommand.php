<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Firewall;

use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
	name: 'iikiti:firewall:ip-allow',
	description: 'Add an IP/CIDR allow rule (exempts matching clients from later block/report rules).',
)]
final class FirewallIpAllowCommand extends Command
{
	public function __construct(private readonly FirewallIpRuleCommandSupport $support)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addArgument('cidr', InputArgument::REQUIRED, 'IPv4/IPv6 address or CIDR range.')
			->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site ID for a site-scoped rule; 0 for global.', '0')
			->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Operator-facing reason stored with the rule.', '')
			->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Lower values are evaluated first.', '0')
			->addOption('required', null, InputOption::VALUE_NONE, 'Make the rule required so sites cannot override it.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			$normalized = IpCidrNormalizer::normalize((string) $input->getArgument('cidr'));
			$siteId = FirewallIpRuleCommandSupport::parseSiteId($input);
			$priority = FirewallIpRuleCommandSupport::parsePriority($input);
		} catch (\InvalidArgumentException $exception) {
			return $this->support->writeError($output, $exception->getMessage());
		}

		$rule = new FirewallIpRule();
		$rule->setCidr($normalized['cidr']);
		$rule->setAction('allow');
		$rule->setInheritance($input->getOption('required') ? 'required' : 'default');
		$rule->setReason((string) $input->getOption('reason'));
		$rule->setPriority($priority);
		$rule->setEnabled(true);
		$this->support->persist($rule, $siteId);

		$output->writeln(sprintf('Allow rule created for %s (site %d).', $normalized['cidr'], $siteId));

		return Command::SUCCESS;
	}
}
