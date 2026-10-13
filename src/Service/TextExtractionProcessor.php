<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TextExtractionProcessor implements DigitalAssetProcessorInterface
{
	public function __construct(
		private readonly AssetStorage $storage,
		private readonly EntityManagerInterface $entityManager,
		private readonly Filesystem $filesystem = new Filesystem(),
	) {
	}

	public function getKey(): string
	{
		return 'text_extract';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return in_array($mimePattern, ['application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json', 'application/xml', 'text/xml'], true);
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		if (!$this->supportsMimePattern($asset->getMimeType())) {
			throw new \InvalidArgumentException('Unsupported text extraction MIME type.');
		}
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded text asset has no stored file.');
		}
		$source = $this->storage->pathFor($sourceKey);
		$maxBytes = filter_var($workflow->getOptions()['max_text_bytes'] ?? 1000000, FILTER_VALIDATE_INT);
		if (false === $maxBytes || $maxBytes < 1 || $maxBytes > 5000000) {
			throw new \InvalidArgumentException('Text extraction byte limit must be between 1 and 5,000,000.');
		}

		if ('application/pdf' === $asset->getMimeType()) {
			$text = $this->extractPdf($source, $workflow, $maxBytes);
		} else {
			$text = file_get_contents($source, false, null, 0, $maxBytes + 1);
			if (!is_string($text)) {
				throw new \RuntimeException('Text asset could not be read.');
			}
		}
		$text = mb_scrub(str_replace("\0", '', $text), 'UTF-8');
		$text = mb_strcut($text, 0, $maxBytes, 'UTF-8');
		$asset->setExtractedText(trim($text));
		$this->entityManager->flush();
	}

	private function extractPdf(string $source, AssetWorkflowDefinition $workflow, int $maxBytes): string
	{
		$maxPages = filter_var($workflow->getOptions()['max_pages'] ?? 200, FILTER_VALIDATE_INT);
		if (false === $maxPages || $maxPages < 1 || $maxPages > 2000) {
			throw new \InvalidArgumentException('PDF page limit must be between 1 and 2,000.');
		}
		$outputPath = $this->storage->createTemporaryPath('txt');
		try {
			$process = new Process(['pdftotext', '-f', '1', '-l', (string) $maxPages, '-enc', 'UTF-8', '-nopgbrk', $source, $outputPath]);
			$process->setTimeout(30)->mustRun();
			$text = file_get_contents($outputPath, false, null, 0, $maxBytes + 1);
			if (!is_string($text)) {
				throw new \RuntimeException('Extracted PDF text could not be read.');
			}

			return $text;
		} finally {
			$this->filesystem->remove($outputPath);
		}
	}
}
