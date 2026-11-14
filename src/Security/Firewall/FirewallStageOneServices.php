<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRouteClassifier;

/**
 * Collaborators for the metadata-only Stage 1 gate. Grouped so the subscriber's constructor
 * stays readable and Stage-2-only construction needs just one optional argument.
 */
final readonly class FirewallStageOneServices
{
	public function __construct(
		public FirewallProtectionPolicyResolverInterface $policyResolver,
		public FirewallIpRuleStore $ipRules,
		public FirewallBotDetector $bots,
		public FirewallChallengeManager $challenges,
		public FirewallDosGuard $dosGuard,
		public FirewallRateLimiterManager $rateLimiter,
		public FirewallAnomalyDetector $anomalies,
		public FirewallAutoBanManager $autoBans,
		public FirewallRouteClassifier $routes,
	) {
	}
}
