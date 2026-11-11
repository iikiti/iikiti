<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Secrets;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iikiti:secrets:rotate-keys', description: 'Re-wrap site secret master keys with the active wrapping key.')]
final class RotateSecretsKeysCommand extends Command
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly SiteMasterKeyManager $masterKeys,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$changed = $this->masterKeys->rewrapAll();
			$this->entityManager->flush();
			$connection->commit();
		} catch (\Throwable $exception) {
			if ($connection->isTransactionActive()) {
				$connection->rollBack();
			}
			$this->entityManager->clear();
			$io->error($exception->getMessage());

			return Command::FAILURE;
		}

		$io->success(sprintf('Re-wrapped %d site master key(s).', $changed));

		return Command::SUCCESS;
	}
}
