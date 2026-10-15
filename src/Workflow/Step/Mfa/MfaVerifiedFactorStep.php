<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Step\Mfa;

use iikiti\CMS\Workflow\Step\AbstractFormWorkflowStep;
use Symfony\Component\Form\Extension\Core\Type\FormType;

final class MfaVerifiedFactorStep extends AbstractFormWorkflowStep
{
	public function __construct(string $id, string $factorName, private readonly string $proofKey)
	{
		parent::__construct($id, sprintf('%s (already verified)', $factorName), FormType::class, true, false);
	}

	public function process(array $data, array $context): array
	{
		return [$this->proofKey => true];
	}
}
