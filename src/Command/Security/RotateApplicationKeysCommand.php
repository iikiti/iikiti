<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Crypto\ApplicationKeyRotatorInterface;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use iikiti\CMS\Transfer\SiteTransferCredentialVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'iikiti:security:keys:rotate', description: 'Re-wrap and re-encrypt stored application secrets with active keys.')]
final class RotateApplicationKeysCommand extends Command
{
	/** @var list<ApplicationKeyRotatorInterface> */
	private readonly array $rotators;

	/**
	 * @param iterable<ApplicationKeyRotatorInterface> $rotators
	 */
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly SiteMasterKeyManager $masterKeys,
		private readonly ?SiteTransferCredentialVault $transferCredentials = null,
		#[AutowireIterator('iikiti.crypto.key_rotator')]
		iterable $rotators = [],
	) {
		parent::__construct();

		$collected = [];
		$seenIds = [];
		foreach ($rotators as $rotator) {
			$id = spl_object_id($rotator);
			$seenIds[$id] = true;
			$collected[] = $rotator;
		}
		if (null !== $transferCredentials && !isset($seenIds[spl_object_id($transferCredentials)])) {
			$collected[] = $transferCredentials;
		}
		$this->rotators = $collected;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		$results = [];
		try {
			$siteKeys = $this->masterKeys->rewrapAll();
			foreach ($this->rotators as $rotator) {
				$results[] = [$rotator->getLabel(), $rotator->rotate()];
			}
			$this->entityManager->flush();
			$connection->commit();
		} catch (\Throwable) {
			if ($connection->isTransactionActive()) {
				$connection->rollBack();
			}
			$this->entityManager->clear();
			$io->error('Application key rotation failed; no changes were committed.');

			return Command::FAILURE;
		}

		$transferCount = 0;
		$extraParts = [];
		foreach ($results as [$label, $count]) {
			if ('transfer credentials' === $label) {
				$transferCount += $count;
			} else {
				$extraParts[] = sprintf('%d %s', $count, $label);
			}
		}

		$message = sprintf(
			'Re-wrapped %d site master key(s) and re-encrypted %d transfer credential(s)',
			$siteKeys,
			$transferCount,
		);
		if ([] !== $extraParts) {
			$message .= ' (+ '.implode(', ', $extraParts).')';
		}

		$io->success($message.'.');

		return Command::SUCCESS;
	}
}
