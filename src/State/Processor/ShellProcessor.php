<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\ShellResource;
use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ShellRepository;
use iikiti\CMS\Web\Template\ShellValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Create/update for global shells. Validation runs before anything is persisted.
 *
 * @implements ProcessorInterface<ShellResource, ShellResource>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class ShellProcessor implements ProcessorInterface
{
	public function __construct(
		private ShellRepository $shellRepository,
		private EntityManagerInterface $entityManager,
		private ShellValidator $validator,
		private Security $security,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		return match ($operation->getName()) {
			'admin_shell_create' => $this->save(new Shell(), $data, true),
			'admin_shell_update' => $this->save($this->find($uriVariables['id'] ?? null), $data, false),
			default => $data,
		};
	}

	private function find(int|string|null $id): Shell
	{
		$shell = $this->shellRepository->find($id);
		if (null === $shell) {
			throw new NotFoundHttpException(sprintf('Shell %s not found.', $id));
		}

		return $shell;
	}

	/**
	 * Sets the NOT NULL site and creator a new shell needs (see DbObject).
	 */
	private function assignOwnership(Shell $shell): void
	{
		$user = $this->security->getUser();
		$shell->setProperties(new ArrayCollection());
		$shell->setSite(SiteRegistry::getCurrent());
		$shell->setCreatorId($user instanceof User ? (int) $user->getId() : 0);
		$shell->setCreatedDate(new \DateTimeImmutable());
	}

	private function save(Shell $shell, ShellResource $resource, bool $isNew): ShellResource
	{
		$role = (string) ($resource->role ?? '');
		$blocks = $resource->blocks ?? [];
		$displayRules = $resource->displayRules ?? [];

		$errors = $this->validator->validate($role, $blocks, $displayRules);
		if ([] !== $errors) {
			throw new BadRequestHttpException(implode(' ', $errors));
		}

		if ($isNew) {
			$this->assignOwnership($shell);
		}

		$shell->setRole($role);
		$shell->setName((string) ($resource->name ?? ''));
		$shell->setPriority((int) ($resource->priority ?? 0));
		$shell->setEnabled($resource->enabled ?? true);
		$shell->setDisplayRules($displayRules);
		$shell->setBlocks($blocks);

		$this->entityManager->persist($shell);
		$this->entityManager->flush();

		return ShellResource::fromEntity($shell);
	}
}
