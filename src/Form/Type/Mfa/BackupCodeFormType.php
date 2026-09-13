<?php

namespace iikiti\CMS\Form\Type\Mfa;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Collects a single-use backup code.
 *
 * @extends AbstractType<array<string,mixed>>
 */
class BackupCodeFormType extends AbstractType
{
	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		$builder->add('code', TextType::class, [
			'label' => 'Backup code',
			'attr' => [
				'autocomplete' => 'one-time-code',
				'autofocus' => true,
				'autocapitalize' => 'characters',
			],
			'constraints' => [
				new NotBlank(message: 'Enter a backup code.'),
				new Regex(pattern: '/^[A-Za-z0-9-]{6,}$/', message: 'Enter a valid backup code.'),
			],
		]);
	}

	public function configureOptions(OptionsResolver $resolver): void
	{
		$resolver->setDefault('data_class', null);
	}
}
