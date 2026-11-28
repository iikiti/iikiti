<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Repository;

use iikiti\CMS\Repository\DigitalAssetFilterCriteria;
use iikiti\CMS\Repository\DigitalAssetRepository;
use PHPUnit\Framework\TestCase;

final class DigitalAssetFilterCriteriaTest extends TestCase
{
	public function testFromQueryArrayParsesAllFiltersSortAndPagination(): void
	{
		$criteria = DigitalAssetFilterCriteria::fromQueryArray([
			'q' => '  banner  ',
			'mimeCategory' => 'IMAGE',
			'mimeType' => 'image/webp',
			'siteId' => '12',
			'visibility' => 'PUBLIC',
			'processingStatus' => 'COMPLETE',
			'folderId' => '45',
			'includeSubfolders' => 'true',
			'collectionId' => '7',
			'tagIds' => '3, 5, 3',
			'licenseStatus' => 'expiring_soon',
			'sort' => 'title_asc',
			'page' => '3',
			'itemsPerPage' => '20',
		]);

		self::assertSame('banner', $criteria->query);
		self::assertSame('image', $criteria->mimeCategory);
		self::assertSame('image/webp', $criteria->mimeType);
		self::assertSame('12', $criteria->siteId);
		self::assertSame('public', $criteria->visibility);
		self::assertSame('complete', $criteria->processingStatus);
		self::assertSame('45', $criteria->folderId);
		self::assertFalse($criteria->unfiledOnly);
		self::assertTrue($criteria->includeSubfolders);
		self::assertSame('7', $criteria->collectionId);
		self::assertSame(['3', '5'], $criteria->tagIds);
		self::assertSame('expiring_soon', $criteria->licenseStatus);
		self::assertSame('title_asc', $criteria->sort);
		self::assertSame(20, $criteria->limit);
		self::assertSame(40, $criteria->offset);
	}

	public function testUnfiledFolderShorthandClearsFolderId(): void
	{
		$criteria = DigitalAssetFilterCriteria::fromQueryArray([
			'folderId' => 'unfiled',
			'tagIds' => ['10', '20'],
		]);

		self::assertTrue($criteria->unfiledOnly);
		self::assertNull($criteria->folderId);
		self::assertSame(['10', '20'], $criteria->tagIds);
	}

	public function testWithoutFacetClearsOnlyTargetDimension(): void
	{
		$criteria = DigitalAssetFilterCriteria::fromQueryArray([
			'mimeCategory' => 'image',
			'visibility' => 'public',
			'processingStatus' => 'complete',
			'siteId' => '4',
			'folderId' => '9',
		]);

		$withoutVis = $criteria->withoutFacet('visibility');
		self::assertNull($withoutVis->visibility);
		self::assertSame('image', $withoutVis->mimeCategory);
		self::assertSame('complete', $withoutVis->processingStatus);
		self::assertSame('4', $withoutVis->siteId);
		self::assertSame('9', $withoutVis->folderId);
	}

	public function testInvalidSortThrowsInvalidArgumentException(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DigitalAssetFilterCriteria::fromQueryArray(['sort' => 'random_order']);
	}

	public function testInvalidVisibilityThrowsInvalidArgumentException(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DigitalAssetFilterCriteria::fromQueryArray(['visibility' => 'secret']);
	}

	public function testClassifyMimeCategoryCoversAllCategories(): void
	{
		self::assertSame('image', DigitalAssetRepository::classifyMimeCategory('image/avif'));
		self::assertSame('video', DigitalAssetRepository::classifyMimeCategory('video/mp4'));
		self::assertSame('audio', DigitalAssetRepository::classifyMimeCategory('audio/mpeg'));
		self::assertSame('document', DigitalAssetRepository::classifyMimeCategory('application/pdf'));
		self::assertSame('document', DigitalAssetRepository::classifyMimeCategory('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		self::assertSame('data', DigitalAssetRepository::classifyMimeCategory('application/json'));
		self::assertSame('data', DigitalAssetRepository::classifyMimeCategory('text/xml'));
		self::assertSame('text', DigitalAssetRepository::classifyMimeCategory('text/plain'));
		self::assertSame('other', DigitalAssetRepository::classifyMimeCategory('application/octet-stream'));
	}

	public function testAcceptsExpiringOrExpiredLicenseStatus(): void
	{
		$criteria = DigitalAssetFilterCriteria::fromQueryArray([
			'licenseStatus' => 'expiring_or_expired',
		]);

		self::assertSame('expiring_or_expired', $criteria->licenseStatus);
		self::assertContains('expiring_or_expired', DigitalAssetFilterCriteria::ALLOWED_LICENSE_STATUSES);
	}
}
