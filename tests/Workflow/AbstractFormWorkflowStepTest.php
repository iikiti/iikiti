<?php

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Form\Type\Mfa\EmailVerificationFormType;
use iikiti\CMS\Workflow\Step\AbstractFormWorkflowStep;
use PHPUnit\Framework\TestCase;

final class AbstractFormWorkflowStepTest extends TestCase
{
	public function testDefaults(): void
	{
		$step = new class extends AbstractFormWorkflowStep {
			public function __construct()
			{
				parent::__construct('email', 'Email', EmailVerificationFormType::class);
			}
		};

		$this->assertSame(EmailVerificationFormType::class, $step->getFormType());
		$this->assertSame([], $step->getFormOptions([]));
		$this->assertSame([], $step->prepare([]));
		$this->assertTrue($step->validate([]));
		$this->assertSame(['code' => '123456'], $step->process(['code' => '123456'], []));
	}
}
