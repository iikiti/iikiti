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
	name: 'iikiti:firewall:ip-block',
	description: 'Add an IP/CIDR block rule, optionally expiring after a TTL.',
)]
final class FirewallIpBlockCommand extends Command
{
	private const MAX_TTL_SECONDS = 31536000;

	public function __construct(private readonly FirewallIpRuleCommandSupport $support)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addArgument('cidr', InputArgument::REQUIRED, 'IPv4/IPv6 address or CIDR range.')
			->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site ID for a site-scoped rule; 0 for global.', '0')
			->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Expire the block after this many seconds (omit for permanent).')
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
			$expiresAt = $this->expiresAt($input);
		} catch (\InvalidArgumentException $exception) {
			return $this->support->writeError($output, $exception->getMessage());
		}

		$rule = new FirewallIpRule();
		$rule->setCidr($normalized['cidr']);
		$rule->setAction('block');
		$rule->setInheritance($input->getOption('required') ? 'required' : 'default');
		$rule->setReason((string) $input->getOption('reason'));
		$rule->setPriority($priority);
		$rule->setEnabled(true);
		$rule->setExpiresAt($expiresAt);
		$this->support->persist($rule, $siteId);

		$output->writeln(sprintf('Block rule created for %s (site %d).', $normalized['cidr'], $siteId));

		return Command::SUCCESS;
	}

	private function expiresAt(InputInterface $input): ?\DateTimeImmutable
	{
		$ttl = $input->getOption('ttl');
		if (null === $ttl) {
			return null;
		}

		$seconds = filter_var($ttl, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_TTL_SECONDS]]);
		if (false === $seconds) {
			throw new \InvalidArgumentException(sprintf('The --ttl option must be between 1 and %d seconds.', self::MAX_TTL_SECONDS));
		}

		return (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $seconds));
	}
}
