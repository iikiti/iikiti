<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\ShellResource;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ShellRepository;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\Template\ShellValidator;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Session-authenticated shell management for the editor Layout dialog and the
 * Layouts admin screen. The browser UI uses the session cookie (main firewall)
 * with a CSRF token on every write; external tools use the token-only
 * `/api/admin/shells` endpoints instead.
 */
#[AsController]
class LayoutController extends AppController
{
	public const CSRF_TOKEN_ID = 'layout_shells';

	public function __construct(
		Security $security,
		ContainerInterface $container,
		private readonly ShellRepository $shellRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly ShellValidator $validator,
		private readonly CsrfTokenManagerInterface $csrfTokenManager,
	) {
		parent::__construct($security, $container);
	}

	#[Route('/admin/layouts/shells', name: 'admin_layouts_shells_list', methods: ['GET'])]
	public function list(): JsonResponse
	{
		if (!$this->isAdmin()) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}

		/** @var list<Shell> $shells */
		$shells = $this->shellRepository->findAll();

		return $this->json([
			'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
			'shells' => array_map(static fn (Shell $shell): array => (array) ShellResource::fromEntity($shell), $shells),
		]);
	}

	#[Route('/admin/layouts/shells', name: 'admin_layouts_shells_create', methods: ['POST'])]
	public function create(Request $request): JsonResponse
	{
		return $this->write($request, new Shell(), true);
	}

	#[Route('/admin/layouts/shells/{id}', name: 'admin_layouts_shells_update', methods: ['PUT'])]
	public function update(Request $request, int $id): JsonResponse
	{
		$shell = $this->shellRepository->find($id);
		if (!$shell instanceof Shell) {
			return $this->json(['error' => 'Shell not found'], Response::HTTP_NOT_FOUND);
		}

		return $this->write($request, $shell, false);
	}

	#[Route('/admin/layouts/shells/{id}', name: 'admin_layouts_shells_delete', methods: ['DELETE'])]
	public function delete(Request $request, int $id): JsonResponse
	{
		if (!$this->isAdmin()) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		if (!$this->validCsrf($request)) {
			return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
		}
		$shell = $this->shellRepository->find($id);
		if (!$shell instanceof Shell) {
			return $this->json(['error' => 'Shell not found'], Response::HTTP_NOT_FOUND);
		}

		$this->entityManager->remove($shell);
		$this->entityManager->flush();

		return $this->json(['ok' => true]);
	}

	private function write(Request $request, Shell $shell, bool $isNew): JsonResponse
	{
		if (!$this->isAdmin()) {
			return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
		}
		if (!$this->validCsrf($request)) {
			return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
		}

		$payload = json_decode((string) $request->getContent(), true);
		if (!is_array($payload)) {
			return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
		}

		$role = (string) ($payload['role'] ?? '');
		$blocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];
		$displayRules = is_array($payload['displayRules'] ?? null) ? array_values($payload['displayRules']) : [];

		$errors = $this->validator->validate($role, $blocks, $displayRules);
		if ([] !== $errors) {
			return $this->json(['error' => implode(' ', $errors)], Response::HTTP_BAD_REQUEST);
		}

		if ($isNew) {
			$this->assignOwnership($shell);
		}
		$shell->setRole($role);
		$shell->setName((string) ($payload['name'] ?? ''));
		$shell->setPriority((int) ($payload['priority'] ?? 0));
		$shell->setEnabled((bool) ($payload['enabled'] ?? true));
		$shell->setDisplayRules($displayRules);
		$shell->setBlocks($blocks);

		$this->entityManager->persist($shell);
		$this->entityManager->flush();

		return $this->json(ShellResource::fromEntity($shell), $isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
	}

	/**
	 * Sets the NOT NULL site and creator a new shell needs (see DbObject).
	 */
	private function assignOwnership(Shell $shell): void
	{
		$user = $this->getUser();
		$site = SiteRegistry::getCurrent();
		$shell->setProperties(new ArrayCollection());
		$shell->setSite($site);
		$shell->setCreatorId($user instanceof User ? (int) $user->getId() : 0);
		$shell->setCreatedDate(new \DateTimeImmutable());
	}

	private function isAdmin(): bool
	{
		return $this->getUser() instanceof User && $this->isGranted('ROLE_ADMIN');
	}

	private function validCsrf(Request $request): bool
	{
		return $this->csrfTokenManager->isTokenValid(
			new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN')),
		);
	}
}
