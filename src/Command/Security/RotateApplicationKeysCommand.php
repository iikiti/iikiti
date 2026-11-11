<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use iikiti\CMS\Transfer\SiteTransferCredentialVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iikiti:security:keys:rotate', description: 'Re-wrap and re-encrypt stored application secrets with active keys.')]
final class RotateApplicationKeysCommand extends Command
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly SiteMasterKeyManager $masterKeys,
		private readonly SiteTransferCredentialVault $transferCredentials,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$siteKeys = $this->masterKeys->rewrapAll();
			$transferCredentials = $this->transferCredentials->reencryptAll();
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

		$io->success(sprintf(
			'Re-wrapped %d site master key(s) and re-encrypted %d transfer credential(s).',
			$siteKeys,
			$transferCredentials,
		));

		return Command::SUCCESS;
	}
}
