<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\CoreFirewallRules;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRequestContextFactory;
use iikiti\CMS\Security\Firewall\FirewallRequestSubscriber;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use iikiti\CMS\Security\Firewall\FirewallRuleSetCache;
use iikiti\CMS\Security\Firewall\FirewallRuleSetResolver;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class FirewallRequestSubscriberTest extends TestCase
{
	public function testRunsAfterRoutingAndSiteInitializationButBeforeSymfonySecurity(): void
	{
		self::assertSame(['onKernelRequest', 9], FirewallRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST]);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->clearCurrentSite();
	}

	protected function tearDown(): void
	{
		$this->clearCurrentSite();
		parent::tearDown();
	}

	public function testSiteLessApiRequestUsesGlobalRulesAndRetainsInspectableFindings(): void
	{
		$request = Request::create(
			'/api/internal/probe',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/json'],
			'{"payload":"<script>alert(1)</script>"}',
		);
		[$event, $securityEvents] = $this->evaluate($request);

		self::assertNull($event->getResponse());
		self::assertCount(1, $securityEvents);
		self::assertSame(null, $securityEvents[0]->requestSummary['siteId']);
		self::assertNotEmpty($securityEvents[0]->findings);
		self::assertSame('report', $securityEvents[0]->action->value);
		self::assertSame('{"payload":"<script>alert(1)</script>"}', $request->getContent());
	}

	public function testSiteLessNonApiRequestIsNotEvaluated(): void
	{
		[$event, $securityEvents] = $this->evaluate(Request::create('/login'));

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
	}

	public function testSiteBoundApiRequestAppliesSitePolicy(): void
	{
		$site = $this->siteWithRules([[
			'id' => 'deny-private-api',
			'name' => 'Block private API',
			'action' => 'deny',
			'conditions' => ['condition' => 'request.match', 'options' => [
				'target' => 'path', 'operator' => 'contains', 'value' => '/api/private',
			]],
		]]);
		$request = Request::create('/api/private/items');
		$request->attributes->set('_api_operation_name', 'private_api_items');
		[$event, $securityEvents] = $this->evaluate($request, $site);

		self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
		self::assertSame('site.17:deny-private-api', $securityEvents[0]->findings[0]->ruleId);
	}

	public function testFirewallPolicyControlPlaneStillOmitsSiteRules(): void
	{
		$site = $this->siteWithRules([[
			'id' => 'deny-policy-endpoint',
			'name' => 'Block policy endpoint',
			'action' => 'deny',
			'conditions' => ['condition' => 'request.match', 'options' => [
				'target' => 'path', 'operator' => 'contains', 'value' => '/api/security/firewall/policy',
			]],
		]]);
		$request = Request::create('/api/security/firewall/policy');
		$request->attributes->set('_api_operation_name', 'site_firewall_policy_get');
		[$event, $securityEvents] = $this->evaluate($request, $site);

		self::assertNull($event->getResponse());
		self::assertCount(1, $securityEvents);
		self::assertSame([], $securityEvents[0]->findings);
	}

	public function testStageTwoOnlyConstructionEmitsNoStageOneMetadata(): void
	{
		$request = Request::create('/api/items');
		$request->attributes->set('_api_operation_name', 'items_get');
		[$event, $securityEvents] = $this->evaluate($request);

		self::assertNull($event->getResponse());
		self::assertCount(1, $securityEvents);
		self::assertSame([], $securityEvents[0]->stageMetadata, 'Stage-2-only construction emits no Stage-1 metadata.');
	}

	public function testDisabledFirewallBypassesWafAndDispatchesNoEvents(): void
	{
		$request = Request::create(
			'/api/internal/probe',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/json'],
			'{"payload":"<script>alert(1)</script>"}',
		);
		[$event, $securityEvents] = $this->evaluate($request, null, false);

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
		self::assertFalse($request->attributes->has(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE));
	}

	public function testDisabledFirewallSkipsSiteBoundPolicyEnforcement(): void
	{
		$site = $this->siteWithRules([[
			'id' => 'deny-private-api',
			'name' => 'Block private API',
			'action' => 'deny',
			'conditions' => ['condition' => 'request.match', 'options' => [
				'target' => 'path', 'operator' => 'contains', 'value' => '/api/private',
			]],
		]]);
		$request = Request::create('/api/private/items');
		$request->attributes->set('_api_operation_name', 'private_api_items');
		[$event, $securityEvents] = $this->evaluate($request, $site, false);

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
	}

	/**
	 * @return array{RequestEvent,list<FirewallSecurityEvent>}
	 */
	private function evaluate(Request $request, ?Site $site = null, bool $enabled = true): array
	{
		$this->clearCurrentSite();
		if (null !== $site) {
			SiteRegistry::pushSite($site);
		}

		$conditions = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$validator = new FirewallRuleValidator($conditions);
		$cache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$sitePolicies = new FirewallSitePolicyStore();
		$resolver = new FirewallRuleSetResolver(
			new CoreFirewallRules(),
			new FirewallRulePackStore(sys_get_temp_dir().'/iikiti-firewall-subscriber-test', new FirewallRulePackSourceRegistry(), $validator, $cache),
			$sitePolicies,
			$validator,
			$conditions,
			$cache,
		);
		$dispatcher = new EventDispatcher();
		$securityEvents = [];
		$dispatcher->addListener(FirewallSecurityEvent::NAME, static function (FirewallSecurityEvent $event) use (&$securityEvents): void {
			$securityEvents[] = $event;
		});
		$subscriber = new FirewallRequestSubscriber(
			new FirewallRequestContextFactory(),
			$resolver,
			new FirewallRuleEvaluator($validator, $conditions),
			$dispatcher,
			new NullLogger(),
			null,
			'api',
			$enabled,
		);
		$kernel = $this->createStub(HttpKernelInterface::class);
		$event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onKernelRequest($event);

		return [$event, $securityEvents];
	}

	/** @param list<array<string,mixed>> $rules */
	private function siteWithRules(array $rules): Site
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 17);
		$site->setProperties(new ArrayCollection());
		$store = new FirewallSitePolicyStore();
		$store->setRules($site, $rules);

		return $site;
	}

	private function clearCurrentSite(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}
}
