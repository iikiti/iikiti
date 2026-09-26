<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\TemplateResource;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Repository\Object\TemplateRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles admin create/update of front-end block-editor templates.
 *
 * The primary template authoring path remains the live editor
 * (`/api/editor/{context}/save`); this processor backs the admin Templates
 * CRUD screens so templates can be managed outside the editor.
 *
 * @implements ProcessorInterface<TemplateResource, TemplateResource>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class TemplateProcessor implements ProcessorInterface
{
	public function __construct(
		private TemplateRepository $templateRepository,
		private EntityManagerInterface $entityManager,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		return match ($operation->getName()) {
			'admin_template_create' => $this->create($data),
			'admin_template_update' => $this->update($data, $uriVariables['id'] ?? null),
			default => $data,
		};
	}

	private function create(TemplateResource $resource): TemplateResource
	{
		$template = new Template();
		// DbObject::$properties is an uninitialized typed Collection on a bare
		// `new` — match PageRendering.php and initialize it before setProperty().
		$template->setProperties(new ArrayCollection());
		$this->apply($template, $resource);

		$this->entityManager->persist($template);
		$this->entityManager->flush();

		return TemplateResource::fromEntity($template);
	}

	private function update(TemplateResource $resource, int|string|null $id): TemplateResource
	{
		$template = $this->templateRepository->find($id);
		if (null === $template) {
			throw new NotFoundHttpException(sprintf('Template %s not found.', $id));
		}

		$this->apply($template, $resource);

		$this->entityManager->persist($template);
		$this->entityManager->flush();

		return TemplateResource::fromEntity($template);
	}

	private function apply(Template $template, TemplateResource $resource): void
	{
		$template->setProperty('title', $resource->title ?? '');
		$template->setLayout($resource->layout ?: Template::DEFAULT_LAYOUT);
		$template->setRegions($this->asArray($resource->regions));
		$template->setAssignments($this->asArray($resource->assignments));

		if (null !== $resource->blocks) {
			$template->setProperty('blocks', $this->asArray($resource->blocks));
		}

		if (null !== $resource->settings) {
			$template->setProperty('settings', $this->asArray($resource->settings));
		}
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function asArray(mixed $value): array
	{
		if (\is_array($value)) {
			return array_values($value);
		}

		return [];
	}
}
