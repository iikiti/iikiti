<?php

namespace iikiti\CMS\Form\Type\Mfa;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Collects the numeric code e-mailed to a user.
 *
 * @extends AbstractType<array<string,mixed>>
 */
class EmailVerificationFormType extends AbstractType
{
	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		$builder->add('code', TextType::class, [
			'label' => 'Verification code',
			'attr' => [
				'inputmode' => 'numeric',
				'autocomplete' => 'one-time-code',
				'autofocus' => true,
				'maxlength' => 6,
			],
			'constraints' => [
				new NotBlank(message: 'Enter the verification code.'),
				new Regex(pattern: '/^\d{6}$/', message: 'The verification code must be 6 digits.'),
			],
		]);
	}

	public function configureOptions(OptionsResolver $resolver): void
	{
		$resolver->setDefaults([
			'data_class' => null,
			'email' => null,
		]);
		$resolver->setAllowedTypes('email', ['null', 'string']);
	}
}
