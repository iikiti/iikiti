<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Service\AssetStorage;
use iikiti\CMS\Service\TextExtractionProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DigitalAssetWorkflowTest extends TestCase
{
	private string $directory;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->directory = dirname(__DIR__, 2).'/var/test-digital-assets-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->directory);
	}

	public function testWorkflowMatchesExactAndWildcardMimePatterns(): void
	{
		$imageWorkflow = new AssetWorkflowDefinition('image-flow', 'Images', 'image_thumbnail', 'image/*');
		$pdfWorkflow = new AssetWorkflowDefinition('pdf-flow', 'PDF text', 'text_extract', 'application/pdf');

		self::assertTrue($imageWorkflow->matches('image/webp'));
		self::assertFalse($imageWorkflow->matches('application/pdf'));
		self::assertTrue($pdfWorkflow->matches('application/pdf'));
		self::assertFalse($pdfWorkflow->matches('application/json'));
	}

	public function testTextProcessorStoresBoundedExtractedText(): void
	{
		$storage = new AssetStorage($this->directory, $this->filesystem);
		$key = 'ab/'.str_repeat('a', 64).'.txt';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);
		file_put_contents($path, '0123456789abcdefgh');
		$asset = new UploadedDigitalAsset('Notes', 'text/plain', str_repeat('c', 64));
		$asset->setStorageKey($key);
		$asset->setSize(18);
		$workflow = new AssetWorkflowDefinition('text-limited', 'Text', 'text_extract', 'text/plain', [], ['max_text_bytes' => 10]);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$processor = new TextExtractionProcessor($storage, $entityManager, $this->filesystem);
		$processor->process($asset, $workflow);

		self::assertSame('0123456789', $asset->getExtractedText());
	}

	public function testStorageRejectsTraversalKeys(): void
	{
		$storage = new AssetStorage($this->directory, $this->filesystem);

		$this->expectException(\InvalidArgumentException::class);
		$storage->pathFor('../private.txt');
	}

	public function testStorageCannotBeConfiguredInsidePublicRoot(): void
	{
		$publicDirectory = dirname(__DIR__, 2).'/public';

		$this->expectException(\InvalidArgumentException::class);
		new AssetStorage($publicDirectory.'/assets', $this->filesystem, $publicDirectory);
	}
}
