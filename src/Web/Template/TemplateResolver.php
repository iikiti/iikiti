<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Template;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see Template} that applies to a given {@see TemplateResolutionContext}
 * by evaluating each template's assignment rules (extensible via
 * {@see TemplateRuleInterface}) and picking the highest-priority match.
 */
final class TemplateResolver
{
	/**
	 * @param iterable<TemplateRuleInterface> $rules
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		#[AutowireIterator('iikiti.cms.template_rule')]
		private readonly iterable $rules = [],
	) {
	}

	/**
	 * Returns the resolved template, or the repository default when none match.
	 */
	public function resolve(TemplateResolutionContext $context): ?Template
	{
		if (null !== $context->object) {
			$templateId = $context->object->getProperties()->get('template_id')?->getValue();
			if (null !== $templateId) {
				$resolved = $this->em->find(Template::class, (int) $templateId);
				if ($resolved instanceof Template) {
					return $resolved;
				}
			}
		}

		// DQL (not the ObjectRepository query builder, whose type filter does not
		// match the stored short discriminator).
		if (null !== $context->site) {
			/** @var list<Template> $candidates */
			$candidates = $this->em->createQuery('SELECT t FROM ' . Template::class . ' t WHERE t.site = :site')
				->setParameter('site', $context->site)
				->getResult();
		} else {
			$candidates = $this->em->getRepository(Template::class)->findAll();
		}

		$best = null;
		$bestPriority = -1;

		foreach ($candidates as $template) {
			$priority = $this->matchPriority($template, $context);
			if ($priority > $bestPriority) {
				$best = $template;
				$bestPriority = $priority;
			}
		}

		return $best;
	}

	private function matchPriority(Template $template, TemplateResolutionContext $context): int
	{
		$priority = 0;
		$matchedAny = false;

		foreach ($template->getAssignments() as $assignment) {
			$ruleType = (string) ($assignment['rule'] ?? '');
			$config = is_array($assignment['config'] ?? null) ? $assignment['config'] : [];
			$assignmentPriority = (int) ($assignment['priority'] ?? 0);

			foreach ($this->rules as $rule) {
				if ($rule->getName() !== $ruleType) {
					continue;
				}
				if ($rule->matches($config, $context)) {
					$matchedAny = true;
					if ($assignmentPriority > $priority) {
						$priority = $assignmentPriority;
					}
				}
			}
		}

		return $matchedAny ? $priority : -1;
	}
}
