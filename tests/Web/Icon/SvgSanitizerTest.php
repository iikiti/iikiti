<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Icon;

use iikiti\CMS\Web\Icon\SvgRejectedException;
use iikiti\CMS\Web\Icon\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
	private function sanitize(string $svg): string
	{
		return (new SvgSanitizer())->sanitize($svg);
	}

	public function testAcceptsCleanIconAndKeepsGeometry(): void
	{
		$clean = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h22v22H1z" stroke="currentColor"/></svg>');

		self::assertStringContainsString('<path', $clean);
		self::assertStringContainsString('d="M1 1h22v22H1z"', $clean);
		self::assertStringContainsString('viewBox="0 0 24 24"', $clean);
	}

	public function testRejectsScriptElement(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
	}

	public function testRejectsEventHandlerAttribute(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>');
	}

	public function testRejectsEventHandlerOnChildElement(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" onclick="x()"/></svg>');
	}

	public function testRejectsForeignObject(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>hi</div></foreignObject></svg>');
	}

	public function testRejectsExternalHrefOnUse(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/x.svg#a"/></svg>');
	}

	public function testRejectsJavascriptUrl(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0"/></a></svg>');
	}

	public function testRejectsDoctypeAndEntities(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>');
	}

	public function testRejectsNonSvgRoot(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<html><body>not svg</body></html>');
	}

	public function testRejectsMalformedXml(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg><path d="M0 0"></svg>');
	}

	public function testRejectsStyleElementToAvoidCssExfiltration(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example/x.css);</style></svg>');
	}

	public function testRejectsUnknownElementRatherThanSilentlyDropping(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><iframe src="https://evil.example"/></svg>');
	}

	public function testRejectsEmptyInput(): void
	{
		$this->expectException(SvgRejectedException::class);
		$this->sanitize('');
	}
}
