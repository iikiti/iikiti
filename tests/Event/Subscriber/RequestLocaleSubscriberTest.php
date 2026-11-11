<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Event\Subscriber;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Event\Subscriber\RequestLocaleSubscriber;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\LocaleResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class RequestLocaleSubscriberTest extends TestCase
{
	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, true);
		parent::tearDown();
	}

	public function testAppliesUserLocaleAndTimezoneAfterSiteContextIsKnown(): void
	{
		$this->initializeSiteStack();
		$site = new Site();
		$site->setLocaleSettings('fr-CA');
		SiteRegistry::pushSite($site);

		$user = new User();
		$user->setPreferredLocale('en-CA');
		$user->setTimeZone('America/Toronto');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$applications = $this->createStub(ApplicationRegistry::class);
		$applications->method('hasCurrentApplication')->willReturn(false);
		$translator = $this->createMock(LocaleAwareInterface::class);
		$translator->expects(self::once())->method('setLocale')->with('en_CA');
		$request = Request::create('/');

		$subscriber = new RequestLocaleSubscriber(
			$security,
			$applications,
			new LocaleResolver(),
			$translator,
		);
		$subscriber->onKernelRequest($this->requestEvent($request));

		self::assertSame('en_CA', $request->getLocale());
		self::assertSame([
			'ui' => 'en-CA',
			'presentation' => 'en-CA',
			'userPreferred' => 'en-CA',
			'timeZone' => 'America/Toronto',
		], $request->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE));
	}

	public function testUsesSiteLocaleWhenThereIsNoUserPreference(): void
	{
		$this->initializeSiteStack();
		$site = new Site();
		$site->setLocaleSettings('fr-CA');
		SiteRegistry::pushSite($site);

		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(null);
		$applications = $this->createStub(ApplicationRegistry::class);
		$applications->method('hasCurrentApplication')->willReturn(false);
		$translator = $this->createMock(LocaleAwareInterface::class);
		$translator->expects(self::once())->method('setLocale')->with('fr_CA');
		$request = Request::create('/');

		$subscriber = new RequestLocaleSubscriber(
			$security,
			$applications,
			new LocaleResolver(),
			$translator,
		);
		$subscriber->onKernelRequest($this->requestEvent($request));

		self::assertSame('fr_CA', $request->getLocale());
		self::assertSame('fr-CA', $request->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE)['presentation']);
		self::assertNull($request->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE)['userPreferred']);
	}

	private function initializeSiteStack(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, true);
	}

	private function requestEvent(Request $request): RequestEvent
	{
		return new RequestEvent(
			$this->createStub(HttpKernelInterface::class),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
		);
	}
}
