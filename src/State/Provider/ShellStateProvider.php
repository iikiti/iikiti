<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\ShellResource;
use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Repository\Object\ShellRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read side for the Layouts admin screen: list and fetch global shells.
 *
 * @implements ProviderInterface<ShellResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class ShellStateProvider implements ProviderInterface
{
	public function __construct(
		private ShellRepository $shellRepository,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		if ('admin_shell_get' === $operation->getName()) {
			$shell = $this->shellRepository->find($uriVariables['id'] ?? null);
			if (!$shell instanceof Shell) {
				throw new NotFoundHttpException('Shell not found.');
			}

			return ShellResource::fromEntity($shell);
		}

		/** @var list<Shell> $shells */
		$shells = $this->shellRepository->findAll();

		return array_map(static fn (Shell $shell): ShellResource => ShellResource::fromEntity($shell), $shells);
	}
}
