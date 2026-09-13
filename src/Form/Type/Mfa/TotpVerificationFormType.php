<?php

namespace iikiti\CMS\Form\Type\Mfa;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Collects a code from an authenticator application.
 *
 * @extends AbstractType<array<string,mixed>>
 */
class TotpVerificationFormType extends AbstractType
{
	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		$builder->add('code', TextType::class, [
			'label' => 'Authenticator code',
			'attr' => [
				'inputmode' => 'numeric',
				'autocomplete' => 'one-time-code',
				'autofocus' => true,
				'maxlength' => 8,
			],
			'constraints' => [
				new NotBlank(message: 'Enter the authenticator code.'),
				new Regex(pattern: '/^\d{6,8}$/', message: 'The authenticator code must be 6 to 8 digits.'),
			],
		]);
	}

	public function configureOptions(OptionsResolver $resolver): void
	{
		$resolver->setDefault('data_class', null);
	}
}
