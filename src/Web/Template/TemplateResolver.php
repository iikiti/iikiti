<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Doctrine\Persistence\ObjectRepository;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Repository\Object\TemplateRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see Template} that applies to a given {@see TemplateResolutionContext}
 * by evaluating each template's assignment rules (extensible via
 * {@see TemplateRuleInterface}) and picking the highest-priority match.
 */
final class TemplateResolver
{
	/**
	 * @param ObjectRepository<Template>      $repository
	 * @param iterable<TemplateRuleInterface> $rules
	 */
	public function __construct(
		#[Autowire(service: TemplateRepository::class)]
		private readonly ObjectRepository $repository,
		#[AutowireIterator('iikiti.cms.template_rule')]
		private readonly iterable $rules = [],
	) {
	}

	/**
	 * Returns the resolved template, or the repository default when none match.
	 */
	public function resolve(TemplateResolutionContext $context): ?Template
	{
		// Explicit per-object override takes precedence.
		if (null !== $context->object) {
			$templateId = $context->object->getProperties()->get('template_id')?->getValue();
			if (null !== $templateId) {
				$resolved = $this->repository->find((int) $templateId);
				if ($resolved instanceof Template) {
					return $resolved;
				}
			}
		}

		/** @var list<Template> $candidates */
		$candidates = null !== $context->site ?
			$this->repository->findBy(['site' => $context->site]) :
			$this->repository->findAll();

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
