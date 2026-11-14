<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Firewall;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared persistence and option parsing for firewall IP rule console commands.
 *
 * Kept as a helper rather than a base command so each command keeps its own
 * `AsCommand` metadata and explicit option surface.
 */
final class FirewallIpRuleCommandSupport
{
	private const MAX_SITE_ID_LENGTH = 20;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly FirewallIpRuleStore $ruleStore,
	) {
	}

	/**
	 * @throws \InvalidArgumentException when the site option is malformed
	 */
	public static function parseSiteId(InputInterface $input): int
	{
		$raw = (string) ($input->getOption('site') ?? '0');
		if (!preg_match('/^(0|[1-9][0-9]{0,'.(self::MAX_SITE_ID_LENGTH - 1).'})$/D', $raw)) {
			throw new \InvalidArgumentException('The --site option must be a non-negative integer.');
		}

		return (int) $raw;
	}

	/**
	 * @throws \InvalidArgumentException when the priority option is malformed
	 */
	public static function parsePriority(InputInterface $input): int
	{
		$priority = filter_var($input->getOption('priority'), FILTER_VALIDATE_INT);
		if (false === $priority) {
			throw new \InvalidArgumentException('The --priority option must be an integer.');
		}

		return $priority;
	}

	/** Stores a rule for the given site scope and invalidates its compiled IP matcher. */
	public function persist(FirewallIpRule $rule, int $siteId): void
	{
		$rule->setSiteId($siteId);
		$rule->setCreatedDate(new \DateTimeImmutable());
		$rule->setCreatorId(0);
		$this->entityManager->persist($rule);
		$this->entityManager->flush();
		$this->ruleStore->invalidateSite($siteId);
	}

	public function writeError(OutputInterface $output, string $message): int
	{
		$output->writeln('<error>'.$message.'</error>');

		return Command::FAILURE;
	}
}
