<?php

declare(strict_types=1);

namespace iikiti\Extension\Forms\BlockEditor;

use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Web\BlockEditor\Render\BlockTreeValidatorInterface;
use iikiti\CMS\Web\BlockEditor\Render\BlockTreeViolation;

final class FormBlockTreeValidator implements BlockTreeValidatorInterface
{
	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function validate(array $tree): ?BlockTreeViolation
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-forms')) {
			return null;
		}

		$violation = FormBlockRule::firstViolation($tree);
		if (null === $violation) {
			return null;
		}

		return new BlockTreeViolation(
			$violation->region,
			$violation->index,
			$violation->type,
			'Form, fieldset, and legend blocks must follow the allowed form hierarchy.',
		);
	}
}
