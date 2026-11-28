<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Command;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Repository\DigitalAssetVersionRepository;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\AssetStorageRegistry;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates uploaded digital asset originals, historical versions, and generated
 * variants between configured {@see AssetStorageInterface} drivers in
 * keyset-paginated batches.
 */
#[AsCommand(
	name: 'iikiti:asset:migrate-storage',
	description: 'Migrate uploaded digital asset originals, versions, and variants between storage backends.',
)]
final class MigrateAssetStorageCommand extends Command
{
	private const IN_MEMORY_BUFFER_BYTES = 2097152;

	private readonly AssetMimeTypeRegistry $mimeRegistry;

	public function __construct(
		private readonly AssetStorageRegistry $storageRegistry,
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetVariantRepository $variantRepository,
		private readonly ?DigitalAssetVersionRepository $versionRepository = null,
		?AssetMimeTypeRegistry $mimeRegistry = null,
	) {
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source storage driver name.', 'local')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Destination storage driver name.', 's3')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of digital assets to process per batch.', '100')
			->addOption('verify-checksum', null, InputOption::VALUE_NONE, 'Verify SHA-256 checksum on the destination before optionally deleting source objects.')
			->addOption('delete-source', null, InputOption::VALUE_NONE, 'Delete source objects after successful migration (and checksum verification if enabled).')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect and report objects to migrate without writing or deleting any data.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$from = strtolower(trim((string) $input->getOption('from')));
		$to = strtolower(trim((string) $input->getOption('to')));
		$rawBatchSize = (string) $input->getOption('batch-size');
		$verifyChecksum = (bool) $input->getOption('verify-checksum');
		$deleteSource = (bool) $input->getOption('delete-source');
		$dryRun = (bool) $input->getOption('dry-run');

		if ('' === $from || '' === $to) {
			$io->error('Both --from and --to storage drivers must be specified.');

			return self::FAILURE;
		}
		if ($from === $to) {
			$io->error(sprintf('Source and destination storage drivers cannot both be "%s".', $from));

			return self::FAILURE;
		}
		if (!ctype_digit($rawBatchSize) || (int) $rawBatchSize < 1 || (int) $rawBatchSize > 10000) {
			$io->error('Option --batch-size must be an integer between 1 and 10000.');

			return self::FAILURE;
		}
		$batchSize = (int) $rawBatchSize;

		try {
			$sourceStorage = $this->storageRegistry->get($from);
			$targetStorage = $this->storageRegistry->get($to);
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		$io->title(sprintf('Migrating digital assets from "%s" to "%s"%s', $from, $to, $dryRun ? ' (dry-run)' : ''));

		$stats = [
			'assets' => 0,
			'migrated' => 0,
			'skipped' => 0,
			'verified' => 0,
			'deleted' => 0,
			'missing' => 0,
			'failed' => 0,
		];

		/** @var array<string,bool> $seenKeys */
		$seenKeys = [];
		$lastId = 0;
		while (true) {
			/** @var list<UploadedDigitalAsset> $assets */
			$assets = $this->entityManager->createQueryBuilder()
				->select('asset')
				->from(UploadedDigitalAsset::class, 'asset')
				->andWhere('asset.id > :lastId')
				->setParameter('lastId', $lastId)
				->orderBy('asset.id', 'ASC')
				->setMaxResults($batchSize)
				->getQuery()
				->getResult();

			if ([] === $assets) {
				break;
			}

			foreach ($assets as $asset) {
				++$stats['assets'];
				$assetId = $asset->getId();
				if (null !== $assetId) {
					$lastId = (int) $assetId;
				}

				/** @var list<array{label:string,key:string,mimeType:?string,checksum:?string}> $objects */
				$objects = [];
				$originalKey = $asset->getStorageKey();
				if (null !== $originalKey && '' !== $originalKey && !isset($seenKeys[$originalKey])) {
					$seenKeys[$originalKey] = true;
					$objects[] = [
						'label' => sprintf('asset#%s (original)', (string) ($assetId ?? '?')),
						'key' => $originalKey,
						'mimeType' => $asset->getMimeType(),
						'checksum' => $asset->getChecksum(),
					];
				}

				if (null !== $this->versionRepository) {
					foreach ($this->versionRepository->findByAsset($asset) as $version) {
						$versionKey = $version->getStorageKey();
						if ('' === $versionKey || isset($seenKeys[$versionKey])) {
							continue;
						}
						$seenKeys[$versionKey] = true;
						$objects[] = [
							'label' => sprintf('asset#%s (version:v%d)', (string) ($assetId ?? '?'), $version->getVersionNumber()),
							'key' => $versionKey,
							'mimeType' => $version->getMimeType(),
							'checksum' => $version->getChecksum(),
						];
					}
				}

				foreach ($this->variantRepository->findByAsset($asset) as $variant) {
					$variantKey = $variant->getStorageKey();
					if ('' === $variantKey || isset($seenKeys[$variantKey])) {
						continue;
					}
					$seenKeys[$variantKey] = true;
					$objects[] = [
						'label' => sprintf('asset#%s (variant:%s)', (string) ($assetId ?? '?'), $variant->getKey()),
						'key' => $variantKey,
						'mimeType' => $variant->getMimeType(),
						'checksum' => $variant->getChecksum(),
					];
				}

				foreach ($objects as $object) {
					$this->migrateObject(
						$object['label'],
						$object['key'],
						$object['mimeType'],
						$object['checksum'],
						$sourceStorage,
						$targetStorage,
						$verifyChecksum,
						$deleteSource,
						$dryRun,
						$io,
						$stats,
					);
				}
			}

			$this->entityManager->clear();
		}

		$io->definitionList(
			['Assets scanned' => (string) $stats['assets']],
			['Objects migrated' => (string) $stats['migrated']],
			['Objects skipped (already on destination)' => (string) $stats['skipped']],
			['Checksums verified' => (string) $stats['verified']],
			['Source objects deleted' => (string) $stats['deleted']],
			['Source objects missing' => (string) $stats['missing']],
			['Failures' => (string) $stats['failed']],
		);

		if ($stats['failed'] > 0) {
			$io->error(sprintf('Migration finished with %d failure(s).', $stats['failed']));

			return self::FAILURE;
		}

		$io->success($dryRun ? 'Dry-run complete. No objects were modified.' : 'Asset storage migration completed successfully.');

		return self::SUCCESS;
	}

	/**
	 * @param array{assets:int,migrated:int,skipped:int,verified:int,deleted:int,missing:int,failed:int} $stats
	 */
	private function migrateObject(
		string $label,
		string $key,
		?string $mimeType,
		?string $storedChecksum,
		AssetStorageInterface $sourceStorage,
		AssetStorageInterface $targetStorage,
		bool $verifyChecksum,
		bool $deleteSource,
		bool $dryRun,
		SymfonyStyle $io,
		array &$stats,
	): void {
		try {
			$sourceExists = $sourceStorage->exists($key);
			$targetExists = $targetStorage->exists($key);

			if (!$sourceExists) {
				if ($targetExists) {
					++$stats['skipped'];
				} else {
					++$stats['missing'];
					$io->warning(sprintf('%s: key "%s" does not exist on source or destination.', $label, $key));
				}

				return;
			}

			if ($dryRun) {
				if ($targetExists) {
					++$stats['skipped'];
				} else {
					++$stats['migrated'];
				}

				return;
			}

			$writeMimeType = (null !== $mimeType && '' !== trim($mimeType) && $this->mimeRegistry->isAllowed($mimeType))
				? $mimeType
				: null;

			$computedSourceHash = null;
			if (!$targetExists) {
				$sourceStream = $sourceStorage->readStream($key);
				try {
					if ($verifyChecksum) {
						[$buffer, $computedSourceHash] = $this->bufferAndHashStream($sourceStream);
						try {
							$targetStorage->writeStream($key, $buffer, $writeMimeType);
						} finally {
							fclose($buffer);
						}
					} else {
						$targetStorage->writeStream($key, $sourceStream, $writeMimeType);
					}
				} finally {
					fclose($sourceStream);
				}
				++$stats['migrated'];
			} else {
				++$stats['skipped'];
			}

			if ($verifyChecksum) {
				$expectedHash = $this->normalizeSha256($storedChecksum);
				if (null === $expectedHash) {
					$expectedHash = $computedSourceHash ?? $this->hashStorageObject($sourceStorage, $key);
				}
				$targetHash = $this->hashStorageObject($targetStorage, $key);
				if (!hash_equals($expectedHash, $targetHash)) {
					++$stats['failed'];
					$io->error(sprintf('%s: SHA-256 checksum mismatch for key "%s" (expected %s, got %s). Source retained.', $label, $key, $expectedHash, $targetHash));

					return;
				}
				++$stats['verified'];
			}

			if ($deleteSource) {
				$sourceStorage->delete($key);
				++$stats['deleted'];
			}
		} catch (\Throwable $exception) {
			++$stats['failed'];
			$io->error(sprintf('%s: failed migrating key "%s": %s', $label, $key, $exception->getMessage()));
		}
	}

	/**
	 * @param resource $source
	 *
	 * @return array{0:resource,1:string}
	 */
	private function bufferAndHashStream(mixed $source): array
	{
		$buffer = fopen('php://temp/maxmemory:'.self::IN_MEMORY_BUFFER_BYTES, 'r+b');
		if (false === $buffer) {
			throw new \RuntimeException('Unable to allocate temporary buffer stream for migration.');
		}

		$hashContext = hash_init('sha256');
		try {
			while (!feof($source)) {
				$chunk = fread($source, 65536);
				if (false === $chunk) {
					throw new \RuntimeException('Unable to read from source storage stream.');
				}
				if ('' === $chunk) {
					break;
				}
				hash_update($hashContext, $chunk);
				if (strlen($chunk) !== fwrite($buffer, $chunk)) {
					throw new \RuntimeException('Unable to write to temporary migration buffer.');
				}
			}
			rewind($buffer);
		} catch (\Throwable $exception) {
			fclose($buffer);
			throw $exception;
		}

		return [$buffer, hash_final($hashContext)];
	}

	private function hashStorageObject(AssetStorageInterface $storage, string $key): string
	{
		$stream = $storage->readStream($key);
		try {
			$context = hash_init('sha256');
			hash_update_stream($context, $stream);

			return hash_final($context);
		} finally {
			fclose($stream);
		}
	}

	private function normalizeSha256(?string $checksum): ?string
	{
		if (null === $checksum) {
			return null;
		}
		$normalized = strtolower(trim($checksum));

		return 1 === preg_match('/^[a-f0-9]{64}$/', $normalized) ? $normalized : null;
	}
}
